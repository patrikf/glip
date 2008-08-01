<?php

class GitTreeError extends Exception {}
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
            return NULL;
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
                foreach ($subtree->listRecursive() as $entry => $blob)
                    $r[$node->name . '/' . $entry] = $blob;
            }
            else
                $r[$node->name] = $node->object;
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

    const TREEDIFF_A = 0x01;
    const TREEDIFF_B = 0x02;

    const TREEDIFF_REMOVED = self::TREEDIFF_A;
    const TREEDIFF_ADDED = self::TREEDIFF_B;
    const TREEDIFF_CHANGED = 0x03;

    static public function treeDiff($a_tree, $b_tree)
    {
        $a_blobs = $a_tree ? $a_tree->listRecursive() : array();
        $b_blobs = $b_tree ? $b_tree->listRecursive() : array();

        $a_files = array_keys($a_blobs);
        $b_files = array_keys($b_blobs);

        $changes = array();

        sort($a_files);
        sort($b_files);
        $a = $b = 0;
        while ($a < count($a_files) || $b < count($b_files))
        {
            if ($a < count($a_files) && $b < count($b_files))
                $cmp = strcmp($a_files[$a], $b_files[$b]);
            else
                $cmp = 0;
            if ($b >= count($b_files) || $cmp < 0)
            {
                $changes[$a_files[$a]] = self::TREEDIFF_REMOVED;
                $a++;
            }
            else if ($a >= count($a_files) || $cmp > 0)
            {
                $changes[$b_files[$b]] = self::TREEDIFF_ADDED;
                $b++;
            }
            else
            {
                if ($a_blobs[$a_files[$a]] != $b_blobs[$b_files[$b]])
                    $changes[$a_files[$a]] = self::TREEDIFF_CHANGED;

                $a++;
                $b++;
            }
        }

        return $changes;
    }
}

