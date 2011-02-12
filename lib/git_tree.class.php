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

class GitTreeError extends Exception {}
class GitTreeInvalidPathError extends GitTreeError {}

require_once('git_object.class.php');

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
            $node->is_submodule = ($node->mode == 57344);
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
        uasort($this->nodes, array('GitTree', 'nodecmp'));
	foreach ($this->nodes as $node)
	    $s .= sprintf("%s %s\0%s", base_convert($node->mode, 10, 8), $node->name, $node->object);
	return $s;
    }

    /**
     * @brief Find the tree or blob at a certain path.
     *
     * @throws GitTreeInvalidPathError The path was found to be invalid. This
     * can happen if you are trying to treat a file like a directory (i.e.
     * @em foo/bar where @em foo is a file).
     *
     * @param $path (string) The path to look for, relative to this tree.
     * @returns The GitTree or GitBlob at the specified path, or NULL if none
     * could be found.
     */
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

    /**
     * @brief Recursively list the contents of a tree.
     *
     * @returns (array mapping string to string) An array where the keys are
     * paths relative to the current tree, and the values are SHA-1 names of
     * the corresponding blobs in binary representation.
     */
    public function listRecursive()
    {
        $r = array();

        foreach ($this->nodes as $node)
        {
            if ($node->is_dir)
            {
                if ($node->is_submodule)
                {
                    $r[$node->name. ':submodule'] = $node->object;
                }
                else
                {
                    $subtree = $this->repo->getObject($node->object);
                    foreach ($subtree->listRecursive() as $entry => $blob)
                    {
                        $r[$node->name . '/' . $entry] = $blob;
                    }
                }
            }
            else
            {
                $r[$node->name] = $node->object;
            }
        }

        return $r;
    }

    /**
     * @brief Updates a node in this tree.
     *
     * Missing directories in the path will be created automatically.
     *
     * @param $path (string) Path to the node, relative to this tree.
     * @param $mode Git mode to set the node to. 0 if the node shall be
     * cleared, i.e. the tree or blob shall be removed from this path.
     * @param $object (string) Binary SHA-1 hash of the object that shall be
     * placed at the given path.
     *
     * @returns (array of GitObject) An array of GitObject%s that were newly
     * created while updating the specified node. Those need to be written to
     * the repository together with the modified tree.
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
                $subtree = new GitTree($this->repo);

                $node = new stdClass;
                $node->mode = 040000;
                $node->name = $name;
                $node->is_dir = TRUE;

                $this->nodes[$node->name] = $node;
            }
            $pending = $subtree->updateNode($path, $mode, $object);

            $subtree->rehash();
            $node->object = $subtree->getName();

            $pending[] = $subtree;
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

