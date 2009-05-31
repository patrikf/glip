<?php
/*
 * Copyright (C) 2008 Patrik Fimml
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

class GitObject
{
    public $repo;
    protected $type;
    protected $name = NULL;

    public function getName() {	return $this->name; }
    public function getType() { return $this->type; }

    static public function create($repo, $type)
    {
	if ($type == Git::OBJ_COMMIT)
	    return new GitCommit($repo);
	if ($type == Git::OBJ_TREE)
	    return new GitTree($repo);
	if ($type == Git::OBJ_BLOB)
	    return new GitBlob($repo);
	throw new Exception(sprintf('unhandled object type %d', $type));
    }

    protected function hash($data)
    {
	$hash = hash_init('sha1');
	hash_update($hash, Git::getTypeName($this->type));
	hash_update($hash, ' ');
	hash_update($hash, strlen($data));
	hash_update($hash, "\0");
	hash_update($hash, $data);
	return hash_final($hash, TRUE);
    }

    public function __construct($repo, $type)
    {
	$this->repo = $repo;
	$this->type = $type;
    }

    public function unserialize($data)
    {
	$this->name = $this->hash($data);
	$this->_unserialize($data);
    }

    public function serialize()
    {
	return $this->_serialize();
    }

    public function rehash()
    {
	$this->name = $this->hash($this->serialize());
    }

    public function write()
    {
	$sha1 = sha1_hex($this->name);
	$path = sprintf('%s/objects/%s/%s', $this->repo->dir, substr($sha1, 0, 2), substr($sha1, 2));
	if (file_exists($path))
	    return FALSE;
	$dir = dirname($path);
	if (!is_dir($dir))
	    mkdir(dirname($path), 0770);
	$f = fopen($path, 'ab');
	flock($f, LOCK_EX);
	ftruncate($f, 0);
	$data = $this->serialize();
	$data = Git::getTypeName($this->type).' '.strlen($data)."\0".$data;
	fwrite($f, gzcompress($data));
	fclose($f);
	return TRUE;
    }
}

