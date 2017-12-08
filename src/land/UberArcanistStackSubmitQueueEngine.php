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
  private $baseCommitIds;
  private $headCommitIds;
  private $temporaryBranches;
  private $traceModeEnabled;

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
   * Ensures latest diff of each revision in the stack patches well against latest diff of its parent.
   */
  protected function validate() {
    try {
      $console = PhutilConsole::getConsole();
      $this->buildRevisionIdToDiffIds();
      $console->writeOut("**<bg:blue> %s </bg>** %s\n", "VERIFY", "Starting validations !!");
      $badIndex = $this->ensureStackRebasedCorrectly();
      $this->writeInfo("CHECKING", pht("BadDiff Index is : %s", $badIndex));
      $badDiff = null;
      if ($badIndex > 0) {
        // We need to auto-rebase and
        $this->rebaseAndArcDiffStack($badIndex);
        $console->writeOut("Completed rebasing and arc-diff.\n");
        // Refresh Diff Ids as we have rebased
        $this->buildRevisionIdToDiffIds();
      }
      $console->writeOut("**<bg:green> %s </bg>** %s\n", 'Verification Passed', pht("Ready to land"));
    } finally {
      $this->cleanup();
    }
  }

  private function cleanup() {
    $api = $this->getRepositoryAPI();
    // Go to parent branch.
    $api->execxLocal('checkout %s', $this->getTargetOnto());
    $api->reloadWorkingCopy();
    foreach ($this->temporaryBranches as $revision => $branch) {
      $this->debugLog("Deleting temporary branch %s\n", $branch);
      try {
        $api->execxLocal('branch -D -- %s', $branch);
      } catch (Exception $ex) {
        $this->writeInfo("ARC_CLEANUP_ERROR",
          pht("Unable to remove temporary branch %s failed with error.code=", $branch), $ex);
      }
    }
  }

  protected function pushChangeToSubmitQueue() {
    $this->writeInfo(
      pht('PUSHING'),
      pht('Pushing changes to Submit Queue.'));
    $api = $this->getRepositoryAPI();
    list($out) = $api->execxLocal(
      'config --get remote.%s.url',
      $this->getTargetRemote());

    $remoteUrl = trim($out);
    $stack = $this->generateRevisionDiffMappingForLanding();
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

  /**
   * Create one branch per revision in the stack and apply latest diff of each revision on top
   * of its parent. Ensure no merge conflicts.
   */
  private function ensureStackRebasedCorrectly() {
    $this->baseCommitIds = array();
    $this->headCommitIds = array();
    $repository_api = $this->getRepositoryAPI();
    $base_ref =  $repository_api->getBaseCommit();
    $base_revision = $base_ref;
    $parent_revision_id = null;
    $index = 0;
    // Create temp branches one per diff in stack. We use this branch to do "arc patch <diff_id>" directly
    $this->temporaryBranches = array();
    foreach($this->revisionIdsInStackOrder as $revision_id) {
      try {
        $this->temporaryBranches[$revision_id] = $this->createBranch($base_revision);
        $this->debugLog("Created temporary branch %s for revision %s for direct arc patch" .
          " Base Commit : %s\n", $this->temporaryBranches[$revision_id], $revision_id, $base_revision);
        $local_branch = $this->temporaryBranches[$revision_id];
        $diffIds = $this->revisionIdToDiffIds[$revision_id];
        $latestDiffId = head($diffIds);
        $this->debugLog("Applying diff patch %s to branch %s (revision %s)\n", $latestDiffId, $local_branch, $revision_id);
        $this->runChildWorkflow('patch', array("--diff", $latestDiffId, "--nobranch"), false,
          "ARC_PATCH_ERROR",
          pht("Unable to apply patch %s corresponding to revision %s error.code=", $latestDiffId, $revision_id));
        $repository_api->reloadWorkingCopy();
        // Set Base Commit to be HEAD-1
        $repository_api->setBaseCommit("HEAD~1");
        $this->baseCommitIds[$revision_id] = $repository_api->getBaseCommit();
        $this->headCommitIds[$revision_id] = $repository_api->getHeadCommit();
        $this->debugLog(pht("Revision Id: %s, baseCommit: %s, HeadCommit: %s", $revision_id,
          $this->baseCommitIds[$revision_id], $this->headCommitIds[$revision_id]));

        if ($parent_revision_id != null) {
          if ($this->headCommitIds[$parent_revision_id] != $this->baseCommitIds[$revision_id]) {
            $this->writeWarn("PATCH_APPLY_FAIL",
              pht("Unable to patch rev %s ( Base Commit: %s) on top of its parent %s (Head Commit: %s)",
                $revision_id, $this->baseCommitIds[$revision_id], $parent_revision_id,
                $this->headCommitIds[$parent_revision_id]));
            return $index;
          }
        }

        // For patching next revisions, use the head of the current branch
        $base_revision = null;
        $parent_revision_id = $revision_id;
        $index ++;
      } catch (Exception $exp) {
        echo pht("Unable to patch revision : %s on top of its parent ", $revision_id);
        return $index;
      }
    }

    // Go to parent branch.
    $repository_api->execxLocal('checkout %s', $this->getTargetOnto());
    $repository_api->reloadWorkingCopy();
    return -1;
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
        array("echo", "n", "|", "arc", $workflow),
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

  private function execxLocal($pattern /* , ... */) {
    $args = func_get_args();
    $future = newv('ExecFuture', $args);
    $future->setCWD($this->getRepositoryAPI()->getPath());
    return $future->resolvex();
  }

  protected function getLandingCommits() {
    $result = array();
    foreach ($this->revisionIdsInStackOrder as $revisionId) {
      $topDiffId = head($this->revisionIdToDiffIds[$revisionId]);
      $diff = head($this->getConduit()->callMethodSynchronous(
        'differential.querydiffs',
        array('ids' => array($topDiffId))));
      $properties = idx($diff, 'properties', array());
      $commits = idx($properties, 'local:commits', array());
      $result = array_merge($result, $commits);
    }
    return ipull($result, 'summary');
  }

  private function debugLog(...$message) {
    if ( $this->traceModeEnabled) {
      echo phutil_console_format(call_user_func_array('pht', $message));
    }
  }

  public function rebaseAndArcDiffStack($startIndex) {
    $prevIndex = $startIndex -1;
    //By definition, Prev Index will always be valid
    assert($prevIndex >= 0,"Unexpected: Starting index for rebasing + arc-diff");
    $ancestorBranches = array();
    $revision_id = $this->revisionIdsInStackOrder[$startIndex];
    $parent_revision_id = $this->revisionIdsInStackOrder[$prevIndex];
    $ok = phutil_console_confirm(pht(
      "Revision D%s does not seem to be based-off of latest diffId of revision D%s. ".
      "Do you want arcanist to auto arc-diff %s and its ".
      "dependent diffs?", $revision_id, $parent_revision_id, $revision_id));
    if (!$ok) {
      throw new ArcanistUserAbortException();
    }

    $parentBranch = $this->temporaryBranches[$parent_revision_id];
    $repository_api = $this->getRepositoryAPI();
    $repository_api->execxLocal('checkout %s', $parentBranch);
    $repository_api->reloadWorkingCopy();

    for ( $index=$startIndex; $index< count($this->revisionIdsInStackOrder); $index++) {
      $prevDiff = $this->revisionIdsInStackOrder[$prevIndex];
      $currDiff = $this->revisionIdsInStackOrder[$index];
      $this->writeInfo("REBASE",
        pht('Rebasing diff D%s onto D%s and doing arc-diff for D%s',$currDiff, $prevDiff, $currDiff));
      $currBranch = $this->createBranch(null);
      $this->rebase($currBranch, $parentBranch, true);
      $this->runChildWorkflow('diff',
        array('--update', pht('D%s', $currDiff), 'HEAD^1'),
        true,
        "ARC_DIFF_ERROR",
        pht("arc diff for D%s failed with error.code=", $currDiff));
      $prevIndex = $startIndex;
      $parentBranch = $currBranch;
    }
  }

  private function rebase($targetBranch, $ontoBranch, $verbose) {

    $repository_api = $this->getRepositoryAPI();
    $repository_api->execxLocal('checkout %s', $targetBranch);
    $repository_api->reloadWorkingCopy();

    if ($ontoBranch != null) {
      chdir($repository_api->getPath());
      if ($verbose) {
        echo phutil_console_format(pht('Rebasing **%s** onto **%s**', $targetBranch, $ontoBranch) . "\n");
      }

      if (!$verbose) {
        $this->runCommandSilently(array("echo", "y", "|", "git","rebase", pht("%s", $ontoBranch)));
      } else {
        $err = phutil_passthru('git rebase %s', $ontoBranch);
        if ($err) {
          throw new ArcanistUsageException(pht(
            "'%s' failed. You can abort with '%s', or resolve conflicts " .
            "and use '%s' to continue forward. After resolving the rebase, " .
            "run '%s'.",
            sprintf('git rebase %s', $ontoBranch),
            'git rebase --abort',
            'git rebase --continue',
            'arc diff'));
        }
      }
      $repository_api->reloadWorkingCopy();
    }
  }
}