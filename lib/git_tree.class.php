<?php

class GitTreeError extends Exception {}
class GitTreeNotFoundError extends GitTreeError {}
class GitTreeInvalidPathError extends GitTreeError {}

require_once('git/git_object.class.php');

class GitTree extends GitObject
{
    public $nodes = array();

    public function __construct($repo)
    {
	parent::__construct($repo, Git::OBJ_TREE);
    }

    public function _unserialize($data)
    {
	$this->nodes = array();
	$start = 0;
	while ($start < strlen($data))
	{
	    $node = new stdClass;

	    $pos = strpos($data, "\0", $start);
	    list($node->mode, $node->name) = explode(' ', substr($data, $start, $pos-$start), 2);
	    $node->mode = intval($node->mode, 8);
            $node->is_dir = !!($node->mode & 040000);
	    $node->object = substr($data, $pos+1, 20);
	    $start = $pos+21;

	    $this->nodes[$node->name] = $node;
	}
	unset($data);
    }

    protected static function nodecmp(&$a, &$b)
    {
        return strcmp($a->name, $b->name);
    }

    public function _serialize()
    {
	$s = '';
        /* git requires nodes to be sorted */
        usort($this->nodes, array('GitTree', 'nodecmp'));
	foreach ($this->nodes as $node)
	    $s .= sprintf("%s %s\0%s", base_convert($node->mode, 10, 8), $node->name, $node->object);
	return $s;
    }

    public function find($path)
    {
        if (!is_array($path))
            $path = explode('/', $path);

        while ($path && !$path[0])
            array_shift($path);
        if (!$path)
            return $this->getName();

        if (!isset($this->nodes[$path[0]]))
            throw new GitTreeNotFoundError;
        $cur = $this->nodes[$path[0]]->object;

        array_shift($path);
        while ($path && !$path[0])
            array_shift($path);

        if (!$path)
            return $cur;
        else
        {
            $cur = $this->repo->getObject($cur);
            if (!($cur instanceof GitTree))
                throw new GitTreeInvalidPathError;
            return $cur->find($path);
        }
    }

    public function listRecursive()
    {
        $r = array();

        foreach ($this->nodes as $node)
        {
            if ($node->is_dir)
            {
                $subtree = $this->repo->getObject($node->object);
                foreach ($subtree->listRecursive() as $entry)
                    array_push($r, $node->name . '/' . $entry);
            }
            else
                array_push($r, $node->name);
        }

        return $r;
    }

    /*
     * updateNode:
     * $path: Path to the node.
     * $mode: Git mode to set the node to. 0 if the mode shall be cleared.
     * $object: SHA1 id of the object that shall be referenced by the node.
     *
     * Missing directories in the path will be created automatically.
     *
     * Returns: an array of GitObjects that were newly created, i.e. need to be
     * written.
     */
    public function updateNode($path, $mode, $object)
    {
        if (!is_array($path))
            $path = explode('/', $path);
        $name = array_shift($path);
        if (count($path) == 0)
        {
            /* create leaf node */
            if ($mode)
            {
                $node = new stdClass;
                $node->mode = $mode;
                $node->name = $name;
                $node->object = $object;
                $node->is_dir = !!($mode & 040000);

                $this->nodes[$node->name] = $node;
            }
            else
                unset($this->nodes[$name]);

            return array();
        }
        else
        {
            /* descend one level */
            if (isset($this->nodes[$name]))
            {
                $node = $this->nodes[$name];
                if (!$node->is_dir)
                    throw new GitTreeInvalidPathError;
                $subtree = clone $this->repo->getObject($node->object);
            }
            else
            {
                /* create new tree */
                $subtree = new GitTree;

                $node = new stdClass;
                $node->mode = 040000;
                $node->name = $name;
                $node->is_dir = TRUE;

                $this->nodes[$node->name] = $node;
            }
            $pending = $subtree->updateNode($path, $mode, $object);

            $subtree->rehash();
            $node->object = $subtree->getName();

            array_push($pending, $subtree);
            return $pending;
        }
    }
}

