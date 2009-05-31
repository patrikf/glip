<?php
/*
 * Copyright (C) 2008, 2009 Patrik Fimml
 *
 * This file is part of glip.
 *
 * glip is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.

 * glip is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with glip.  If not, see <http://www.gnu.org/licenses/>.
 */

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
		$meta[$parts[0]][] = $parts[1];
	}

	$this->tree = sha1_bin($meta['tree'][0]);
	$this->parents = array_map('sha1_bin', $meta['parent']);
	$this->author = new GitCommitStamp;
	$this->author->unserialize($meta['author'][0]);
	$this->committer = new GitCommitStamp;
	$this->committer->unserialize($meta['committer'][0]);

	$this->summary = array_shift($lines);
	$this->detail = implode("\n", $lines);

        $this->history = NULL;
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

    /* returns history in topological order */
    public function getHistory()
    {
        if ($this->history)
            return $this->history;

        /* count incoming edges */
        $inc = array();

        $queue = array($this);
        while (($commit = array_shift($queue)) !== NULL)
        {
            foreach ($commit->parents as $parent)
            {
                if (!isset($inc[$parent]))
                {
                    $inc[$parent] = 1;
                    $queue[] = $this->repo->getObject($parent);
                }
                else
                    $inc[$parent]++;
            }
        }

        $queue = array($this);
        $r = array();
        while (($commit = array_pop($queue)) !== NULL)
        {
            array_unshift($r, $commit);
            foreach ($commit->parents as $parent)
            {
                if (--$inc[$parent] == 0)
                    $queue[] = $this->repo->getObject($parent);
            }
        }

        $this->history = $r;
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
};
?>
