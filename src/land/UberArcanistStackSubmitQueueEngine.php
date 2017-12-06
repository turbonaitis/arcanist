<?php
/**
 * Created by PhpStorm.
 * User: varadarb
 * Date: 8/23/17
 * Time: 1:31 PM
 */

final class UberArcanistStackSubmitQueueEngine
  extends UberArcanistSubmitQueueEngine
{

  private $revisionIdsInStackOrder;
  private $revisionIdToDiffIds;
  private $directPatchApplyBranches;
  private $traceModeEnabled;
  private $stdin;
  private $stdout;
  private $stderr;

  /**
   * @return mixed
   */
  public function getRevisionIdsInStackOrder()
  {
    return $this->revisionIdsInStackOrder;
  }

  /**
   * @param mixed $revisionIdsInStackOrder
   */
  public function setRevisionIdsInStackOrder($revisionIdsInStackOrder)
  {
    $this->revisionIdsInStackOrder = $revisionIdsInStackOrder;
    return $this;
  }

  /**
   * @return mixed
   */
  public function getTraceModeEnabled()
  {
    return $this->traceModeEnabled;
  }

  /**
   * @param mixed $traceModeEnabled
   */
  public function setTraceModeEnabled($traceModeEnabled)
  {
    $this->traceModeEnabled = $traceModeEnabled;
    return $this;
  }

  private function gatherStreamHandles() {
    if( ($stdin = fopen( 'php://stdin', 'r' )) === false )
    {
      echo 'Failed to open STDIN'."\n";
      exit();
    }
    if( ($stdout = fopen( 'php://stdout', 'w' )) === false )
    {
      echo 'Failed to open STDOUT'."\n";
      exit();
    }
    if( ($stderr = fopen( 'php://stderr', 'w' )) === false )
    {
      echo 'Failed to open STDERR'."\n";
      exit();
    }
    $this->stdin = $stdin;
    $this->stdout = $stdout;
    $this->stderr = $stderr;
  }
  public function execute()
  {
    $this->gatherStreamHandles();
    $this->verifySourceAndTargetExist();
    $this->fetchTarget();

    $this->printLandingCommits();

    if ($this->getShouldPreview()) {
      $this->writeInfo(
        pht('PREVIEW'),
        pht('Completed preview of operation.'));
      return;
    }

    $this->saveLocalState();
    try {
      if (!$this->getSkipUpdateWorkingCopy()) {
        $this->updateWorkingCopy();
      }

      // At this step source commit point is set
      $this->validate();

      $workflow = $this->getWorkflow();
      if ($workflow->getConfigFromAnySource("uber.land.submitqueue.events.prepush")) {
        // fn dispatches ArcanistEventType::TYPE_LAND_WILLPUSHREVISION for
        // arc-pre-push hooks;  see UberArcPrePushEventListener
        $workflow->didCommitMerge();
      }

      $this->pushChange();
      if (! $this->shouldShadow) {
        // cleanup the local state
        $this->reconcileLocalState();
        $topDiffs = $this->revisionIdToDiffIds[end($this->revisionIdsInStackOrder)];
        $topDiff = $topDiffs[0];
        echo pht("You can also use the below arc command to pull the change you submitted \n".
          "arc patch --diff %s --nobranch\n", $topDiff);
        if ($this->getShouldKeep()) {
          echo tsprintf("%s\n", pht('Keeping local branch.'));
        } else {
          $this->checkoutTarget();
          $this->destroyLocalBranch();
        }
      }
      $this->restoreWhenDestroyed = false;
    }  catch (Exception $ex) {
      $this->restoreLocalState();
      throw $ex;
    } finally {
      $this->cleanup();
    }
  }

  private function cleanup() {
    $this->cleanupTemporaryBranches($this->directPatchApplyBranches);
  }

  private function cleanupTemporaryBranches(&$localBranches) {
    $api = $this->getRepositoryAPI();
    foreach ($localBranches as $revision => $branch) {
      $this->debugLog("Deleting temporary branch %s\n", $branch);
      try {
        $api->execxLocal('branch -D -- %s', $branch);
      } catch (Exception $ex) {
        $this->writeInfo("ARC_CLEANUP_ERROR",
          pht("Unable to remove temporary branch %s failed with error.code=", $branch), $ex);
      }
    }
  }

  private function checkoutTarget() {
    $api = $this->getRepositoryAPI();
    $api->execxLocal("checkout %s --", $this->getTargetOnto());
  }

  private function pushChange() {
    $this->writeInfo(
      pht('PUSHING'),
      pht('Pushing changes to Submit Queue.'));
    $api = $this->getRepositoryAPI();
    list($out) = $api->execxLocal(
      'config --get remote.%s.url',
      $this->getTargetRemote());

    $remoteUrl = trim($out);
    // Get the latest revision as we could have updated the diff
    // as a result of arc diff
    $revision = $this->getRevision();
    $stack = $this->generateRevisionDiffMappingForLanding();
    $this->debugLog("Stack:");
    print_r($this->revisionIdsInStackOrder);
    $statusUrl = $this->submitQueueClient->submitMergeStackRequest(
      $remoteUrl,
      $stack,
      $this->shouldShadow,
      $this->getTargetOnto());
    $this->writeInfo(
      pht('Successfully submitted the request to the Submit Queue.'),
      pht('Please use "%s" to track your changes', $statusUrl));
  }

  private function generateRevisionDiffMappingForLanding() {
    $revisonDiffStack = array();
    foreach ($this->revisionIdsInStackOrder as $revisionId) {
      array_push($revisonDiffStack, array(
        "revisionId" => $revisionId,
        "diffId" => head($this->revisionIdToDiffIds[$revisionId])
      ));
    }
    return $revisonDiffStack;
  }

  private function updateWorkingCopy() {
    $api = $this->getRepositoryAPI();

    $api->execxLocal('checkout %s --', $this->getSourceRef());

    try {
      // merge target against source to generate the latest patch
      $api->execxLocal('merge --no-stat %s --', $this->getTargetFullRef());
    } catch (Exception $ex) {
      $api->execManualLocal('merge --abort');
      $api->execManualLocal('reset --hard HEAD --');

      throw new Exception(
        pht(
          '"%s" does not merge cleanly into Local "%s". Merge or rebase '.
          'local changes so they can merge cleanly.',
          $this->getTargetFullRef(),
          $this->getSourceRef()));
    }
  }

  /**
   * Create one branch per revision in the stack and apply latest diff of each revision on top
   * of its parent. Ensure no merge conflicts.
   */
  private function applyPatches() {
    $repository_api = $this->getRepositoryAPI();
    $base_ref =  $repository_api->getBaseCommit();
    $base_revision = $base_ref;
    // Create temp branches one per diff in stack. We use this branch to do "arc patch <diff_id>" directly
    $this->directPatchApplyBranches = array();
    foreach($this->revisionIdsInStackOrder as $revision_id) {
      $this->directPatchApplyBranches[$revision_id] = $this->createBranch($base_revision);
      $this->debugLog("Created temporary branch %s for revision %s for direct arc patch".
        " Base Commit : %s\n", $this->directPatchApplyBranches[$revision_id], $revision_id, $base_revision);
      $local_branch = $this->directPatchApplyBranches[$revision_id];
      $diffIds = $this->revisionIdToDiffIds[$revision_id];
      $latestDiffId = head($diffIds);
      $this->debugLog("Applying diff patch %s to branch %s (revision %s)\n", $latestDiffId, $local_branch, $revision_id);
      $this->runChildWorkflow('patch', array("--diff", $latestDiffId, "--nobranch"), false,
        "ARC_PATCH_ERROR",
        pht("Unable to apply patch %s corresponding to revision %s error.code=", $latestDiffId, $revision_id));
      $repository_api->reloadWorkingCopy();
      // For patching next revisions, use the head of the current branch
      $base_revision = null;
    }
    // Go to parent branch.
    $repository_api->execxLocal('checkout %s', $this->getTargetOnto());
    $repository_api->reloadWorkingCopy();
  }

  /**
   * Query phab and collect diff ids for each revision
   * @throws ArcanistUsageException
   */
  private function buildRevisionIdToDiffIds() {
    foreach($this->revisionIdsInStackOrder as $revision_id) {
      $revisions = $this->getWorkflow()->getConduit()->callMethodSynchronous(
        'differential.query',
        array(
          'ids' => array($revision_id),
        ));
      if (!$revisions) {
        throw new ArcanistUsageException(pht(
          "No such revision '%s'!",
          "D{$revision_id}"));
      }
      $revision = head($revisions);
      $this->revisionIdToDiffIds[$revision_id] = $revision['diffs'];
    }
  }

  private function normalizeDiff($text) {
    $changes = id(new ArcanistDiffParser())->parseDiff($text);
    ksort($changes);
    return ArcanistBundle::newFromChanges($changes)->toGitPatch();
  }

  private function runCommandSilently($cmdArr) {
    $stdoutFile = tempnam("/tmp", "arc_stack_out_");
    $stderrFile = tempnam("/tmp", "arc_stack_err_");
    $cmd = null;
    try {
      // Pass default-yes (if needed) to the arc command to make it non-interactive.
      $cmdArr = array_merge($cmdArr, array(pht(">%s",$stdoutFile), pht("2>%s", $stderrFile)));
      $cmd = implode(" ", $cmdArr);
      $this->debugLog("Executing cmd (%s)\n", $cmd);
      $this->execxLocal($cmd);
    } catch (Exception $exp) {
      echo pht("Command failed (%s) Output : \n%s\nError : \n%s\n",$cmd,
        file_get_contents($stdoutFile), file_get_contents($stderrFile));
      throw $exp;
    } finally {
      unlink($stderrFile);
      unlink($stdoutFile);
    }
  }
  private function runChildWorkflow($workflow, $paramArray, $passThru, $errTitle, $errMessage) {
    if (!$passThru) {
      $this->runCommandSilently(array_merge(
        array("echo", "y", "|", "arc", $workflow),
        $paramArray));
    } else {
      try {
        $cmdWorkflow = $this->getWorkflow()->buildChildWorkflow($workflow, $paramArray);
        $err = $cmdWorkflow->run();
        if ($err) {
          $this->writeInfo($errTitle, $errMessage . $err);
          throw new ArcanistUserAbortException();
        }
      } catch (Exception $exp) {
        echo pht("Failed executing workflow %s with args (%s).\n", $workflow, implode(",", $paramArray));
        throw $exp;
      }
    }
  }

  /**
   * Ensures latest diff of each revision in the stack patches well against latest diff of its parent.
   */
  public function validate() {
    $console = PhutilConsole::getConsole();
    $this->buildRevisionIdToDiffIds();
    $console->writeOut("**<bg:blue> %s </bg>** %s\n", "VERIFY", "Starting validations !!");
    $this->applyPatches();
    $console->writeOut("**<bg:green> %s </bg>** %s\n", 'Verification Passed', pht("Ready to land"));
  }

  private function createBranch($base_revision) {
    $repository_api = $this->getRepositoryAPI();
    $repository_api->reloadWorkingCopy();
    $branch_name = $this->getBranchName();
    if ($base_revision) {
      $base_revision = $repository_api->getCanonicalRevisionName($base_revision);
      $repository_api->execxLocal('checkout -b %s %s', $branch_name, $base_revision);
    } else {
      $repository_api->execxLocal('checkout -b %s', $branch_name);
    }
    $this->debugLog("%s\n", pht('Created and checked out branch %s.\n', $branch_name));
    $repository_api->reloadWorkingCopy();
    return $branch_name;
  }

  protected function verifySourceAndTargetExist() {
    $api = $this->getRepositoryAPI();

    list($err) = $api->execManualLocal(
      'rev-parse --verify %s',
      $this->getTargetFullRef());

    if ($err) {
      throw new Exception(
        pht(
          'Branch "%s" does not exist in remote "%s".',
          $this->getTargetOnto(),
          $this->getTargetRemote()));
    }

    list($err, $stdout) = $api->execManualLocal(
      'rev-parse --verify %s',
      $this->getSourceRef());

    if ($err) {
      throw new Exception(
        pht(
          'Branch "%s" does not exist in the local working copy.',
          $this->getSourceRef()));
    }

    $this->sourceCommit = trim($stdout);
  }

  private function getBranchName() {
    $branch_name    = null;
    $repository_api = $this->getRepositoryAPI();
    $revision_id    = $this->revision['id'];
    $base_name      = 'arcstack';
    if ($revision_id) {
      $base_name .= "-D{$revision_id}_";
    }

    // Try 100 different branch names before giving up.
    for( $i = 0; $i<100; $i++ )  {
      $proposed_name = $base_name.$i;

      list($err) = $repository_api->execManualLocal(
        'rev-parse --verify %s',
        $proposed_name);

      // no error means git rev-parse found a branch
      if (!$err) {
        $this->debugLog(
          "%s\n",
          pht(
            'Branch name %s already exists; trying a new name.\n',
            $proposed_name));
        continue;
      } else {
        $branch_name = $proposed_name;
        break;
      }
    }

    if (!$branch_name) {
      throw new Exception(
        pht(
          'Arc was unable to automagically make a name for this patch. '.
          'Please clean up your working copy and try again.'));
    }

    return $branch_name;
  }

  private function debugLog(...$message) {
    if ( $this->traceModeEnabled) {
      echo phutil_console_format(call_user_func_array('pht', $message));
    }
  }

  private function execxLocal($pattern /* , ... */) {
    $args = func_get_args();
    $future = newv('ExecFuture', $args);
    $future->setCWD($this->getRepositoryAPI()->getPath());
    return $future->resolvex();
  }
}