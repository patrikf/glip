<?php

require_once('git/git_object.class.php');
require_once('git/git_commit_stamp.class.php');

class GitCommit extends GitObject
{
    public $tree;
    public $parents;
    public $author;
    public $committer;
    public $summary;
    public $detail;

    public function __construct($repo)
    {
	parent::__construct($repo, Git::OBJ_COMMIT);
    }

    public function _unserialize($data)
    {
	$lines = explode("\n", $data);
	unset($data);
	$meta = array('parent' => array());
	while (($line = array_shift($lines)) != '')
	{
	    $parts = explode(' ', $line, 2);
	    if (!isset($meta[$parts[0]]))
		$meta[$parts[0]] = array($parts[1]);
	    else
		array_push($meta[$parts[0]], $parts[1]);
	}

	$this->tree = sha1_bin($meta['tree'][0]);
	$this->parents = array_map('sha1_bin', $meta['parent']);
	$this->author = new GitCommitStamp;
	$this->author->unserialize($meta['author'][0]);
	$this->committer = new GitCommitStamp;
	$this->committer->unserialize($meta['committer'][0]);

	$this->summary = array_shift($lines);
	$this->detail = implode("\n", $lines);
    }

    public function _serialize()
    {
	$s = '';
	$s .= sprintf("tree %s\n", sha1_hex($this->tree));
	foreach ($this->parents as $parent)
	    $s .= sprintf("parent %s\n", sha1_hex($parent));
	$s .= sprintf("author %s\n", $this->author->serialize());
	$s .= sprintf("committer %s\n", $this->committer->serialize());
	$s .= "\n".$this->summary."\n".$this->detail;
	return $s;
    }

    public function getHistory()
    {
        $commits = array($this);
        $r = array();
        while (($commit = array_shift($commits)) !== NULL)
        {
            array_push($r, $commit);
            $commits += array_map(array($this->repo, 'getObject'), $commit->parents);
        }
        usort($r, create_function('$a,$b', 'return ($a->committer->time - $b->committer->time);'));
        return $r;
    }

    public function getTree()
    {
        return $this->repo->getObject($this->tree);
    }

    public function find($path)
    {
        return $this->getTree()->find($path);
    }

    static public function treeDiff($a, $b)
    {
        return GitTree::treeDiff($a ? $a->getTree() : NULL, $b ? $b->getTree() : NULL);
    }
}

