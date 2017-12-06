<?php
/**
 * Created by PhpStorm.
 * User: varadarb
 * Date: 8/22/17
 * Time: 11:59 AM
 */

/**
 * Lands a branch by rebasing, merging and amending it.
 */
final class ArcanistStackWorkflow extends ArcanistWorkflow
{

  private $isGit;

  private $oldBranch;
  private $branch;
  private $onto;
  private $ontoRemoteBranch;
  private $remote;
  private $keepBranch;
  private $branchType;
  private $ontoType;
  private $preview;
  private $shouldRunUnit;
  private $sourceCommit;
  private $submitQueueRegex;
  private $submitQueueUri;
  private $submitQueueShadowMode;
  private $submitQueueClient;

  private $revisions; // Revision Info in stack order
  private $revision_ids; // Stack of revision-ids
  private $messageFile;
  private $tempBranches;
  private $traceModeEnabled;

  public function getWorkflowName()
  {
    return 'stack';
  }

  public function getCommandSynopses()
  {
    return phutil_console_format(<<<EOTEXT
      **stack** [__options__] [__ref__]
EOTEXT
    );
  }

  public function getCommandHelp()
  {
    return phutil_console_format(<<<EOTEXT
          Supports: git

          Submits an accepted stack of diffs after review to Submit Queue for landing. This command is the last
          step in the standard Differential pre-publish code review workflow.

          The workflow selects a target branch to land onto and a remote where
          the change will be pushed to.

          A target branch is selected by examining these sources in order:

            - the **--onto** flag;
            - the upstream of the current branch, recursively;
            - the __arc.land.onto.default__ configuration setting;
            - or by falling back to a standard default:
              - "master" in Git;
              - "default" in Mercurial.

          A remote is selected by examining these sources in order:

            - the **--remote** flag;
            - the upstream of the current branch, recursively (Git only);
            - or by falling back to a standard default:
              - "origin" in Git;
              - the default remote in Mercurial.

          After selecting a target branch and a remote, the commits which will
          be landed are printed.

          With **--preview**, execution stops here, before the change is
          merged.

          In Git, the merge occurs in a detached HEAD. The local branch
          reference (if one exists) is not updated yet.

          With **--hold**, execution stops here, before the change is pushed.

          The change is submitted to Submit Queue.

          Consulting mystical sources of power, the workflow makes a guess
          about what state you wanted to end up in after the process finishes
          and the working copy is put into that state.

          The branch which was landed is deleted, unless the **--keep-branch**
          flag was passed or the landing branch is the same as the target
          branch.

EOTEXT
    );
  }

  public function getArguments() {
    return array(
      'onto' => array(
        'param' => 'master',
        'help' => pht(
          "Land feature branch onto a branch other than the default ".
          "('master' in git). You can change the default ".
          "by setting '%s' with `%s` or for the entire project in %s.",
          'arc.land.onto.default',
          'arc set-config',
          '.arcconfig'),
      ),
      'hold' => array(
        'help' => pht(
          'Prepare the change to be pushed, but do not actually push it.'),
      ),
      'keep-branch' => array(
        'help' => pht(
          'Keep the feature branch after pushing changes to the '.
          'remote (by default, it is deleted).'),
      ),
      'remote' => array(
        'param' => 'origin',
        'help' => pht(
          "Push to a remote other than the default ('origin' in git)."),
      ),
      'revision' => array(
        'param' => 'id',
        'help' => pht(
          'Uses this revision-id instead of the first revision-id found in the commit log of current workspace'),
      ),
      'preview' => array(
        'help' => pht(
          'Prints the commits that would be landed. Does not '.
          'actually modify or land the commits.'),
      ),
      '*' => 'branch',
      'uber-skip-update' => array(
        'help' => pht('uber-skip-update: Skip updating working copy'),
        'supports' => array('git',),
      ),
      'nounit' => array(
        'help' => pht('Do not run unit tests.'),
      ),
    );
  }

  public function requiresWorkingCopy()
  {
    return true;
  }

  public function requiresConduit()
  {
    return true;
  }

  public function requiresAuthentication()
  {
    return true;
  }

  public function requiresRepositoryAPI()
  {
    return true;
  }

  /**
   * @task lintunit
   */
  private function uberRunUnit() {
    if ($this->getArgument('nounit')) {
      return ArcanistUnitWorkflow::RESULT_SKIP;
    }

    $console = PhutilConsole::getConsole();
    $repository_api = $this->getRepositoryAPI();
    $console->writeOut("%s\n", pht('Running unit tests...'));

    try {
      $argv = $this->getPassthruArgumentsAsArgv('unit');
      $argv[] = '--rev';
      $argv[] = $repository_api->getBaseCommit();

      $unit_workflow = $this->buildChildWorkflow('unit', $argv);
      $unit_result = $unit_workflow->run();

      switch ($unit_result) {
        case ArcanistUnitWorkflow::RESULT_OKAY:
          $console->writeOut(
            "<bg:green>** %s **</bg> %s\n",
            pht('UNIT OKAY'),
            pht('No unit test failures.'));
          break;
        case ArcanistUnitWorkflow::RESULT_UNSOUND:
          if ($this->getArgument('ignore-unsound-tests')) {
            echo phutil_console_format(
              "<bg:yellow>** %s **</bg> %s\n",
              pht('UNIT UNSOUND'),
              pht(
                'Unit testing raised errors, but all failing tests are unsound.'));
          } else {
            $continue = $console->confirm(
              pht(
                'Unit test results included failures, but all failing tests '.
                'are known to be unsound. Ignore unsound test failures?'));
            if (!$continue) {
              throw new ArcanistUserAbortException();
            }
          }
          break;
        case ArcanistUnitWorkflow::RESULT_FAIL:
          $console->writeOut(
            "<bg:red>** %s **</bg> %s\n",
            pht('UNIT ERRORS'),
            pht('Unit testing raised errors!'));
          $ok = phutil_console_confirm(pht("Revision does not pass arc unit. Continue anyway?"));
          if (!$ok) {
            throw new ArcanistUserAbortException();
          }
          break;
      }

      $testResults = array();
      foreach ($unit_workflow->getTestResults() as $test) {
        $testResults[] = $test->toDictionary();
      }

      return $unit_result;
    } catch (ArcanistNoEngineException $ex) {
      $console->writeOut(
        "%s\n",
        pht('No unit test engine is configured for this project.'));
    } catch (ArcanistNoEffectException $ex) {
      $console->writeOut("%s\n", $ex->getMessage());
    }

    return null;
  }

  private function readArguments() {
    $this->traceModeEnabled = getenv("ARCANIST_TRACE");
    $repository_api = $this->getRepositoryAPI();
    $this->isGit = $repository_api instanceof ArcanistGitAPI;

    $branch = $this->getArgument('branch');
    if (empty($branch)) {
      $branch = $this->getBranch();
      if ($branch) {
        echo pht("Landing current branch '%s'.", $branch), "\n";
        $branch = array($branch);
      }
    }

    if (count($branch) !== 1) {
      throw new ArcanistUsageException(
        pht('Specify exactly one branch to land changes from.'));
    }
    
    $this->branch = head($branch);
    $this->keepBranch = $this->getArgument('keep-branch');
    $this->preview = $this->getArgument('preview');

    if (!$this->branchType) {
      $this->branchType = $this->getBranchType($this->branch);
    }

    $onto_default = 'master';
    $onto_default = nonempty(
      $this->getConfigFromAnySource('arc.land.onto.default'),
      $onto_default);
    $onto_default = coalesce(
      $this->getUpstreamMatching($this->branch, '/^refs\/heads\/(.+)$/'),
      $onto_default);
    $this->onto = $this->getArgument('onto', $onto_default);
    $this->ontoType = $this->getBranchType($this->onto);

    $remote_default = 'origin';
    $remote_default = coalesce(
      $this->getUpstreamMatching($this->onto, '/^refs\/remotes\/(.+?)\//'),
      $remote_default);
    $this->remote = $this->getArgument('remote', $remote_default);
    $this->ontoRemoteBranch = $this->remote.'/'.$this->onto;
    $this->oldBranch = $this->getBranch();
    $this->shouldRunUnit = nonempty(
      $this->getConfigFromAnySource('uber.land.run.unit'),
      false
    );

    $this->submitQueueUri = $this->getConfigFromAnySource('uber.land.submitqueue.uri');
    $this->submitQueueShadowMode = $this->getConfigFromAnySource('uber.land.submitqueue.shadow');
    $this->submitQueueRegex = $this->getConfigFromAnySource('uber.land.submitqueue.regex');
    if(empty($this->submitQueueUri)) {
      $message = pht("You are trying to use submitqueue, but the submitqueue URI for your repo is not set");
      throw new ArcanistUsageException($message);
    }
    $this->submitQueueClient =
      new UberSubmitQueueClient(
        $this->submitQueueUri,
        $this->getConduit()->getConduitToken());
  }

  private function uberShouldRunSubmitQueue($revision, $regex) {
    if (empty($regex)) {
      return true;
    }

    $diff = head(
      $this->getConduit()->callMethodSynchronous(
        'differential.querydiffs',
        array('ids' => array(head($revision['diffs'])))));
    $changes = array();
    foreach ($diff['changes'] as $changedict) {
      $changes[] = ArcanistDiffChange::newFromDictionary($changedict);
    }

    foreach ($changes as $change) {
      if (preg_match($regex, $change->getOldPath())) {
        return true;
      }

      if (preg_match($regex, $change->getCurrentPath())) {
        return true;
      }
    }

    return false;
  }

  public function run() {

    $this->tempBranches = array();
    $this->readArguments();
    assert($this->isGit, "arc stack supports only git version control");
    try {
      if ($this->shouldRunUnit) {
        $this->uberRunUnit();
      }
      $this->findSourceCommit();
      $uberShadowEngine = null;
      $engine = null;
      $this->findRevision();
      if ($this->uberShouldRunSubmitQueue(head($this->revisions), $this->submitQueueRegex)) {
        // If the shadow-mode is on, then initialize the shadowEngine
        if ($this->submitQueueShadowMode) {
          $uberShadowEngine = new UberArcanistStackSubmitQueueEngine(
            $this->submitQueueClient,
            $this->getConduit());
          $uberShadowEngine =
            $uberShadowEngine
              ->setRevisionIdsInStackOrder($this->revision_ids)
              ->setSkipUpdateWorkingCopy($this->getArgument('uber-skip-update'));
        }
        $engine = new UberArcanistStackSubmitQueueEngine(
          $this->submitQueueClient,
          $this->getConduit());
        $engine->setRevisionIdsInStackOrder($this->revision_ids)
          ->setSkipUpdateWorkingCopy($this->getArgument('uber-skip-update'));
      } else {
        throw new ArcanistUsageException("arc stack only supports Submit queue for pushing diffs");
      }

      $this->readEngineArguments();
      $this->requireCleanWorkingCopy();
      $should_hold = $this->getArgument('hold');
      $this->debugLog("Revision Ids in stack order : %s. Source Ref: (%s), Onto : (%s)", implode(",", $this->revision_ids), $this->branch, $this->onto);
      $engine
        ->setWorkflow($this)
        ->setRepositoryAPI($this->getRepositoryAPI())
        ->setSourceRef($this->branch)
        ->setTargetRemote($this->remote)
        ->setTargetOnto($this->onto)
        ->setShouldHold($should_hold)
        ->setShouldKeep($this->keepBranch)
        ->setShouldSquash(false)
        ->setShouldPreview($this->preview)
        ->setRevisionIdsInStackOrder($this->revision_ids)
        ->setTraceModeEnabled($this->traceModeEnabled)
        ->setBuildMessageCallback(array($this, 'buildEngineMessage'));

      // initialize the shadow engine and execute it if uberShadowEngine is initialized
      if ($uberShadowEngine) {
        $uberShadowEngine
          ->setWorkflow($this)
          ->setRepositoryAPI($this->getRepositoryAPI())
          ->setSourceRef($this->branch)
          ->setTargetRemote($this->remote)
          ->setTargetOnto($this->onto)
          ->setShouldHold($should_hold)
          ->setShouldKeep($this->keepBranch)
          ->setShouldSquash(false)
          ->setShouldPreview($this->preview)
          ->setRevisionIdsInStackOrder($this->revision_ids)
          ->setTraceModeEnabled($this->traceModeEnabled)
          ->setBuildMessageCallback(array($this, 'buildEngineMessage'))
          ->setShouldShadow(true);
        $uberShadowEngine->execute();
      }
      $engine->execute();
      return 0;
    } finally {
      $this->cleanupTemporaryBranches($this->tempBranches);
    }
  }

  public function getSupportedRevisionControlSystems() {
    return array('git');
  }

  private function getBranch() {
    $repository_api = $this->getRepositoryAPI();
    $branch = $repository_api->getBranchName();
    // If we don't have a branch name, just use whatever's at HEAD.
    if (!strlen($branch)) {
      $branch = $repository_api->getWorkingCopyRevision();
    }
    return $branch;
  }

  private function getBranchType($branch) {
    return 'branch';
  }

  private function readEngineArguments() {
    $onto = $this->getEngineOnto();
    $remote = $this->getEngineRemote();

    // This just overwrites work we did earlier, but it has to be up in this
    // class for now because other parts of the workflow still depend on it.
    $this->onto = $onto;
    $this->remote = $remote;
    $this->ontoRemoteBranch = $this->remote.'/'.$onto;
  }

  private function getEngineOnto() {
    $onto = $this->getArgument('onto');
    if ($onto !== null) {
      $this->writeInfo(
        pht('TARGET'),
        pht('Landing onto "%s", selected by the --onto flag.', $onto));
      return $onto;
    }

    $api = $this->getRepositoryAPI();
    $path = $api->getPathToUpstream($this->branch);

    if ($path->getLength()) {
      $cycle = $path->getCycle();
      if ($cycle) {
        $this->writeWarn(
          pht('LOCAL CYCLE'),
          pht('Local branch tracks an upstream, but following it leads to a local cycle; ignoring branch upstream.'));

        echo tsprintf("\n    %s\n\n", implode(' -> ', $cycle));
      } else {
        if ($path->isConnectedToRemote()) {
          $onto = $path->getRemoteBranchName();
          $this->writeInfo(
            pht('TARGET'),
            pht(
              'Landing onto "%s", selected by following tracking branches '.
              'upstream to the closest remote.',
              $onto));
          return $onto;
        } else {
          $this->writeInfo(
            pht('NO PATH TO UPSTREAM'),
            pht(
              'Local branch tracks an upstream, but there is no path '.
              'to a remote; ignoring branch upstream.'));
        }
      }
    }

    $config_key = 'arc.land.onto.default';
    $onto = $this->getConfigFromAnySource($config_key);
    if ($onto !== null) {
      $this->writeInfo(
        pht('TARGET'),
        pht(
          'Landing onto "%s", selected by "%s" configuration.',
          $onto,
          $config_key));
      return $onto;
    }

    $onto = 'master';
    $this->writeInfo(
      pht('TARGET'),
      pht(
        'Landing onto "%s", the default target under git.',
        $onto));
    return $onto;
  }

  private function getEngineRemote() {
    $remote = $this->getArgument('remote');
    if ($remote !== null) {
      $this->writeInfo(
        pht('REMOTE'),
        pht(
          'Using remote "%s", selected by the --remote flag.',
          $remote));
      return $remote;
    }

    $api = $this->getRepositoryAPI();
    $path = $api->getPathToUpstream($this->branch);

    $remote = $path->getRemoteRemoteName();
    if ($remote !== null) {
      $this->writeInfo(
        pht('REMOTE'),
        pht(
          'Using remote "%s", selected by following tracking branches '.
          'upstream to the closest remote.',
          $remote));
      return $remote;
    }

    $remote = 'origin';
    $this->writeInfo(
      pht('REMOTE'),
      pht(
        'Using remote "%s", the default remote under git.',
        $remote));
    return $remote;
  }

  private function findRevision() {
    $repository_api = $this->getRepositoryAPI();
    $this->parseBaseCommitArgument(array($this->ontoRemoteBranch));

    $revision_id = $this->getArgument('revision');
    if ($revision_id) {
      $revision_id = $this->normalizeRevisionID($revision_id);
      $revisions = $this->getConduit()->callMethodSynchronous(
        'differential.query', array('ids' => array($revision_id),));
      if (!$revisions) {
        throw new ArcanistUsageException(pht("No such revision '%s'!", "D{$revision_id}"));
      }
      $revision = head($revisions);
      $diffId = $revision['diffs'][0];
      // We create a temp-branch and arc-patch the revision. This is the working copy
      $this->createTemporaryBranch();
      $patchWorkflow = $this->buildChildWorkflow('patch',
        array("--diff", $diffId, "--nobranch", "--uber-use-merge-strategy"));
      $err = $patchWorkflow->run();
      if ($err) {
        $this->writeInfo("ARC_PATCH_ERROR",
          pht("Unable to apply patch revision %s (diff: %s) error.code=", $revision_id, $diffId), $err);
        throw new ArcanistUserAbortException();
      }
    }

    $revisions = $repository_api->loadWorkingCopyDifferentialRevisions($this->getConduit(), array());
    if (!count($revisions)) {
      throw new ArcanistUsageException(pht(
        "arc can not identify which revision exists on %s '%s'. Update the ".
        "revision with recent changes to synchronize the %s name and hashes, ".
        "or use '%s' to amend the commit message at HEAD, or use ".
        "'%s' to select a revision explicitly.",
        $this->branchType,
        $this->branch,
        $this->branchType,
        'arc amend',
        '--revision <id>'));
    }

    $this->revisions = array_reverse($revisions);
    $this->revision_ids = array();

    foreach ($this->revisions as $revision) {
      $rev_status = $revision['status'];
      $rev_id = $revision['id'];
      $this->revision_ids[] = $this->normalizeRevisionID($rev_id);
      $rev_title = $revision['title'];

      if ($revision['authorPHID'] != $this->getUserPHID()) {
        $other_author = $this->getConduit()->callMethodSynchronous(
          'user.query',
          array(
            'phids' => array($revision['authorPHID']),
          ));
        $other_author = ipull($other_author, 'userName', 'phid');
        $other_author = $other_author[$revision['authorPHID']];
        $ok = phutil_console_confirm(pht(
          "This %s has revision '%s' but you are not the author. Land this " .
          "revision by %s?",
          $this->branchType,
          "D{$rev_id}: {$rev_title}",
          $other_author));
        if (!$ok) {
          throw new ArcanistUserAbortException();
        }
      }

      $uber_prevent_unaccepted_changes = $this->getConfigFromAnySource(
        'uber.land.prevent-unaccepted-changes',
        false);
      if ($uber_prevent_unaccepted_changes && $rev_status != ArcanistDifferentialRevisionStatus::ACCEPTED) {
        throw new ArcanistUsageException(
          pht("Revision '%s' has not been accepted.", "D{$rev_id}: {$rev_title}"));
      }

      if ($rev_status == ArcanistDifferentialRevisionStatus::CLOSED) {
        throw new ArcanistUsageException(
          pht("Revision '%s' has already been closed.", "D{$rev_id}: {$rev_title}"));
      } elseif ($rev_status != ArcanistDifferentialRevisionStatus::ACCEPTED) {
        $ok = phutil_console_confirm(pht(
          "Revision '%s' has not been accepted. Continue anyway?",
          "D{$rev_id}: {$rev_title}"));
        if (!$ok) {
          throw new ArcanistUserAbortException();
        }
      }

      $diff_phid = idx($revision, 'activeDiffPHID');
      if ($diff_phid) {
        $this->checkForBuildables($diff_phid);
      }
      $message = $this->getConduit()->callMethodSynchronous(
        'differential.getcommitmessage', array('revision_id' => $rev_id,));
      $this->messageFile = new TempFile();
      Filesystem::writeFile($this->messageFile, $message);

      echo pht("Adding revision '%s' for landing...", "D{$rev_id}: {$rev_title}")."\n";
    }
    $this->debugLog("Revision Ids in stack order: %s", implode(",", $this->revision_ids));
  }

  private function createTemporaryBranch() {
    $repository_api = $this->getRepositoryAPI();
    $branch_name = $this->getBranchName();
    $base_revision = $this->sourceCommit;
    assert(!empty($base_revision), 'Base Revision not set !!');
    $base_revision = $repository_api->getCanonicalRevisionName($base_revision);
    $repository_api->execxLocal('checkout -b %s %s', $branch_name, $base_revision);
    $this->debugLog("%s\n", pht('Created and checked out branch %s.', $branch_name));
    $this->tempBranches[] = $branch_name;
    return $branch_name;
  }

  private function getBranchName() {
    $branch_name    = null;
    $repository_api = $this->getRepositoryAPI();
    $revision_id    = head($this->revisions)['id'];
    $base_name      = 'arcstack';
    if ($revision_id) {
      $base_name .= "-D{$revision_id}_";
    }

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
            'Branch name %s already exists; trying a new name.',
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

  private function cleanupTemporaryBranches(&$localBranches) {
    $api = $this->getRepositoryAPI();
    foreach ($localBranches as $branch) {
      $this->debugLog("Deleting temporary branch %s\n", $branch);
      try {
        $api->execxLocal('branch -D -- %s', $branch);
      } catch (Exception $ex) {
        $this->writeInfo("ARC_CLEANUP_ERROR",
          pht("Unable to remove temporary branch %s failed with error.code=", $branch), $ex);
      }
    }
  }

  /**
   * Check if a diff has a running or failed buildable, and prompt the user
   * before landing if it does.
   */
  private function checkForBuildables($diff_phid) {
    // NOTE: Since Harbormaster is still beta and this stuff all got added
    // recently, just bail if we can't find a buildable. This is just an
    // advisory check intended to prevent human error.

    try {
      $buildables = $this->getConduit()->callMethodSynchronous(
        'harbormaster.querybuildables',
        array(
          'buildablePHIDs' => array($diff_phid),
          'manualBuildables' => false,
        ));
    } catch (ConduitClientException $ex) {
      return;
    }

    if (!$buildables['data']) {
      // If there's no corresponding buildable, we're done.
      return;
    }

    $console = PhutilConsole::getConsole();

    $buildable = head($buildables['data']);

    if ($buildable['buildableStatus'] == 'passed') {
      $console->writeOut(
        "**<bg:green> %s </bg>** %s\n",
        pht('BUILDS PASSED'),
        pht('Harbormaster builds for the active diff completed successfully.'));
      return;
    }

    switch ($buildable['buildableStatus']) {
      case 'building':
        $message = pht(
          'Harbormaster is still building the active diff for this revision:');
        $prompt = pht('Land revision anyway, despite ongoing build?');
        break;
      case 'failed':
        $message = pht(
          'Harbormaster failed to build the active diff for this revision. '.
          'Build failures:');
        $prompt = pht('Land revision anyway, despite build failures?');
        break;
      default:
        // If we don't recognize the status, just bail.
        return;
    }

    $builds = $this->getConduit()->callMethodSynchronous(
      'harbormaster.querybuilds',
      array(
        'buildablePHIDs' => array($buildable['phid']),
      ));

    $console->writeOut($message."\n\n");
    foreach ($builds['data'] as $build) {
      switch ($build['buildStatus']) {
        case 'failed':
          $color = 'red';
          break;
        default:
          $color = 'yellow';
          break;
      }

      $console->writeOut(
        "    **<bg:".$color."> %s </bg>** %s: %s\n",
        phutil_utf8_strtoupper($build['buildStatusName']),
        pht('Build %d', $build['id']),
        $build['name']);
    }

    $console->writeOut(
      "\n%s\n\n    **%s**: __%s__",
      pht('You can review build details here:'),
      pht('Harbormaster URI'),
      $buildable['uri']);

    if ($this->getConfigFromAnySource("uber.land.buildables-check")) {
      $console->writeOut("\n");
      throw new ArcanistUsageException(
        pht("All harbormaster buildables have not succeeded."));
    }

    if (!$console->confirm($prompt)) {
      throw new ArcanistUserAbortException();
    }
  }

  public function buildEngineMessage(ArcanistLandEngine $engine) {
    // TODO: This is oh-so-gross.
    $this->findRevision();
    $engine->setCommitMessageFile($this->messageFile);
  }

  private function findSourceCommit() {
    $api = $this->getRepositoryAPI();
    list($err, $stdout) = $api->execManualLocal('rev-parse --verify %s', $this->branch);
    if ($err) {
      throw new Exception(pht('Branch "%s" does not exist in the local working copy.', $this->branch));
    }
    $this->sourceCommit = trim($stdout);
  }

  private function getUpstreamMatching($branch, $pattern) {
    if ($this->isGit) {
      $repository_api = $this->getRepositoryAPI();
      list($err, $fullname) = $repository_api->execManualLocal(
        'rev-parse --symbolic-full-name %s@{upstream}',
        $branch);
      if (!$err) {
        $matches = null;
        if (preg_match($pattern, $fullname, $matches)) {
          return last($matches);
        }
      }
    }
    return null;
  }

  private function debugLog(...$message) {
    if ( $this->traceModeEnabled) {
      echo phutil_console_format(call_user_func_array('pht', $message));
    }
  }
}
