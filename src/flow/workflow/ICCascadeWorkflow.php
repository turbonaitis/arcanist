<?php

final class ICCascadeWorkflow extends ICFlowBaseWorkflow {

  public function getWorkflowBaseName() {
    return 'cascade';
  }

  public function getArcanistWorkflowName() {
    return 'cascade';
  }

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **cascade** [--halt-on-conflict]
EOTEXT
      );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT

          Automates the process of rebasing and patching local working branches
          and their associated differential diffs.

EOTEXT
      );
  }

  public function getArguments() {
    return array(
      'halt-on-conflict' => array(
        'help' => "Rather than aborting any rebase attempts, cascade will drop".
                  " the user\n into the conflicted branch in a rebase state.",
        'short' => 'hc',
      ),
    );
  }

  public function run() {
    $this->cascade();
    return 0;
  }

  private function cascade() {
    $graph = $this->loadGitBranchGraph();
    $api = $this->getRepositoryAPI();
    if ($this->isInRebase($api)) {
      throw new ArcanistUsageException(
        phutil_console_format(" You are in a rebase process currently.\n".
                              " Get more information about this by running:".
                              "\n\n".
                              "      git status\n\n".
                              " To abort the current rebase, run:\n\n".
                              "      git rebase --abort\n\n".
                              " Aborting cascade.\n"));
    }

    $branch_name = $api->getBranchName();
    echo "Cascading children of current branch.\n";
    echo ICConsoleTree::drawTreeColumn($branch_name, 0, false, '').PHP_EOL;
    $this->rebaseChildren($graph, $branch_name);
    $this->checkoutBranch($branch_name);
  }

  private function isInRebase($api) {
    list($err, $stdout) = $api->execManualLocal('status');
    $in_rebase = strpos($stdout, 'rebase in progress;') !== false;
    return $in_rebase;
  }

  private function rebaseChildren(ICGitBranchGraph $graph, $branch_name) {
    $api = $this->getRepositoryAPI();
    $downstreams = $graph->getDownstreams($branch_name);
    foreach ($downstreams as $index => $child_branch) {
      echo ICConsoleTree::drawTreeColumn(
        $child_branch,
        $graph->getDepth($child_branch),
        false,
        '');
      list($err, $stdout, $stderr) = $api->execManualLocal(
        'rebase --fork-point %s %s',
        $branch_name,
        $child_branch);
      if ($err) {
        echo phutil_console_format(" <fg:red>%s</fg>\n", 'FAIL');
        if ($this->getArgument('halt-on-conflict') || $this->userHaltConfig()) {
          $conflict = $this->extractConflictFromRebase($stdout);
          throw new ArcanistUsageException(
            phutil_console_format(" <fg:red>%s</fg>\n".
              " Navigate to that file to correct the conflict, then run:\n\n".
              "        git add <file(s)>\n".
              "        git rebase --continue\n\n".
              " Then continue on with cascading. To abort this process, run:".
              "\n\n".
              "        git rebase --abort\n\n".
              " You are now in branch '**%s**'.\n", $conflict, $child_branch));
        } else {
          $api->execxLocal('rebase --abort');
          continue;
        }

      } else {
        echo phutil_console_format(" <fg:green>%s</fg>\n", 'OK');
      }

      $this->rebaseChildren($graph, $child_branch);
    }
  }

  private function userHaltConfig() {
    $should_halt = $this->getConfigFromAnySource('cascade.halt');
    return $should_halt;
  }

  private function extractConflictFromRebase($stdout) {
    // Find conflict, only take the line it is enumerated on using
    $result = null;
    preg_match("/CONFLICT(.*)\n/sU", $stdout, $result);
    return rtrim(head($result));
  }
}
