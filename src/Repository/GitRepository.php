<?php

namespace AssistantEngine\OpenFunctions\Github\Repository;

use Github\AuthMethod;
use Github\Client;
use Github\Exception\ExceptionInterface;

class GitRepository
{
    private $client;
    private $owner;
    private $repo;
    private $baseBranch;
    private $branch; // Active branch

    public function __construct($token, $owner, $repo, $baseBranch = 'main')
    {
        $this->client = new Client();
        $this->client->authenticate($token, null, AuthMethod::ACCESS_TOKEN);

        $this->owner = $owner;
        $this->repo = $repo;
        $this->baseBranch = $baseBranch;
        $this->branch = $baseBranch; // Default to base branch
    }

    // Check if a branch exists
    public function branchExists($branchName)
    {
        try {
            $this->client->api('gitData')->references()->show($this->owner, $this->repo, 'heads/' . $branchName);
            return true;
        } catch (ExceptionInterface $e) {
            if ($e->getCode() == 404) {
                return false;
            } else {
                throw $e;
            }
        }
    }

    // List all branches
    public function listBranches()
    {
        try {
            $branches = $this->client->api('repo')->branches($this->owner, $this->repo);
            return array_column($branches, 'name');
        } catch (ExceptionInterface $e) {
            throw $e;
        }
    }

    // Checkout branch (create if not exists)
    public function checkoutBranch($branchName)
    {
        $this->branch = $branchName;

        if ($this->branchExists($branchName)) {
            // Branch exists, nothing to do
        } else {
            // Create branch from base branch
            $this->createBranch($branchName, $this->baseBranch);
        }
    }

    // Create a new branch from another branch
    private function createBranch($newBranch, $sourceBranch)
    {
        $sourceRef = $this->client->api('gitData')->references()->show($this->owner, $this->repo, 'heads/' . $sourceBranch);
        $sourceSha = $sourceRef['object']['sha'];

        $this->client->api('gitData')->references()->create($this->owner, $this->repo, [
            'ref' => 'refs/heads/' . $newBranch,
            'sha' => $sourceSha,
        ]);
    }

    // List files and directories in a specific path
    public function listDirectory($path = '')
    {
        try {
            $contents = $this->client->api('repo')->contents()->show($this->owner, $this->repo, $path, $this->branch);
            $directories = [];
            $files = [];

            foreach ($contents as $item) {
                if ($item['type'] == 'dir') {
                    $directories[] = $item['path'];
                } elseif ($item['type'] == 'file') {
                    $files[] = $item['path'];
                }
            }

            return ['directories' => $directories, 'files' => $files];
        } catch (ExceptionInterface $e) {
            if ($e->getCode() == 404) {
                // Directory is empty or does not exist
                return ['directories' => [], 'files' => [], "d" => $this->branch];
            } else {
                throw $e;
            }
        }
    }

    public function listFiles($onlyFiles = false)
    {
        $tree = $this->client->api('gitData')->trees()->show($this->owner, $this->repo, $this->branch, true);

        $files = array_filter($tree['tree'], function ($item) use ($onlyFiles) {
            return $onlyFiles ? $item['type'] === 'blob' : true;
        });

        return array_column($files, 'path');
    }


    public function listCommits($branch = null)
    {
        $branch = $branch ?? $this->branch;
        return $this->client->api('repo')->commits()->all($this->owner, $this->repo, ['sha' => $branch]);
    }

    public function getCommitFiles($commitSha)
    {
        $commit = $this->client->api('repo')->commits()->show($this->owner, $this->repo, $commitSha);
        return array_column($commit['files'], 'filename');
    }

    public function readFileAtCommit($filePath, $commitSha)
    {
        $file = $this->client->api('repo')->contents()->show($this->owner, $this->repo, $filePath, $commitSha);
        return base64_decode($file['content']);
    }

    // Read file content
    public function readFile($filePath)
    {
        $file = $this->client->api('repo')->contents()->show($this->owner, $this->repo, $filePath, $this->branch);
        return base64_decode($file['content']);
    }

    // Modify or create file
    public function modifyFile($filePath, $newContent, $commitMessage)
    {
        $committer = [
            'name' => 'Assistant Engine',
            'email' => 'assistantengine@gmail.com',
        ];

        try {
            // Check if file exists
            $existingFile = $this->client->api('repo')->contents()->show($this->owner, $this->repo, $filePath, $this->branch);
            $params['sha'] = $existingFile['sha'];

            // Update file
            $this->client->api('repo')->contents()->update(
                $this->owner,
                $this->repo,
                $filePath,
                $newContent,
                $commitMessage,
                $params['sha'],
                $this->branch,
                $committer
            );
        } catch (ExceptionInterface $e) {
            if ($e->getCode() == 404) {
                // File does not exist, create it
                $this->client->api('repo')->contents()->create(
                    $this->owner,
                    $this->repo,
                    $filePath,
                    $newContent,
                    $commitMessage,
                    $this->branch,
                    $committer
                );
            } else {
                throw $e;
            }
        }
    }

    // Modify or create multiple files in a single commit
    public function modifyFiles(array $files, $commitMessage)
    {
        $committer = [
            'name'  => 'Assistant Engine',
            'email' => 'assistantengine@gmail.com',
        ];

        // Step 1: Get the current commit SHA of the branch
        $reference = $this->client->api('gitData')->references()->show(
            $this->owner,
            $this->repo,
            'heads/' . $this->branch
        );
        $currentCommitSha = $reference['object']['sha'];

        // Step 2: Get the tree SHA of the current commit
        $currentCommit = $this->client->api('gitData')->commits()->show(
            $this->owner,
            $this->repo,
            $currentCommitSha
        );
        $baseTreeSha = $currentCommit['tree']['sha'];

        // Step 3: Create blobs and collect tree data
        $treeData = [];
        foreach ($files as $file) {
            $filePath = $file['path'];
            $newContent = $file['content'];

            // Create a blob
            $blobData = $this->client->api('gitData')->blobs()->create(
                $this->owner,
                $this->repo,
                [
                    'content'  => $newContent,
                    'encoding' => 'utf-8',
                ]
            );
            $blobSha = $blobData['sha'];

            // Prepare tree data
            $treeData[] = [
                'path' => $filePath,
                'mode' => '100644',
                'type' => 'blob',
                'sha'  => $blobSha,
            ];
        }

        // Step 4: Create a new tree
        $newTree = $this->client->api('gitData')->trees()->create(
            $this->owner,
            $this->repo,
            [
                'base_tree' => $baseTreeSha,
                'tree'      => $treeData,
            ]
        );
        $newTreeSha = $newTree['sha'];

        // Step 5: Create a new commit
        $newCommit = $this->client->api('gitData')->commits()->create(
            $this->owner,
            $this->repo,
            [
                'message'   => $commitMessage,
                'tree'      => $newTreeSha,
                'parents'   => [$currentCommitSha],
                'author'    => $committer,
                'committer' => $committer,
            ]
        );
        $newCommitSha = $newCommit['sha'];

        // Step 6: Update the reference to point to the new commit
        $this->client->api('gitData')->references()->update(
            $this->owner,
            $this->repo,
            'heads/' . $this->branch,
            [
                'sha' => $newCommitSha,
            ]
        );

        return true;
    }

    // Create a pull request
    public function createPullRequest($title, $body = '')
    {
        // Check if a pull request already exists
        $existingPR = $this->findExistingPullRequest();

        if ($existingPR) {
            // Pull request already exists
            return $existingPR;
        } else {
            // Create new pull request
            $prData = $this->client->api('pullRequest')->create($this->owner, $this->repo, [
                'title' => $title,
                'head' => $this->branch,
                'base' => $this->baseBranch,
                'body' => $body,
            ]);

            return $prData;
        }
    }

    // Find existing pull request
    private function findExistingPullRequest()
    {
        $prs = $this->client->api('pullRequest')->all($this->owner, $this->repo, [
            'state' => 'open',
            'head' => "{$this->owner}:{$this->branch}",
            'base' => $this->baseBranch,
        ]);

        return !empty($prs) ? $prs[0] : null;
    }

    // Getter for the current branch
    public function getBranch()
    {
        return $this->branch;
    }
}