<?php

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
	$f = fopen($path, 'a');
	flock($f, LOCK_EX);
	ftruncate($f, 0);
	$data = $this->serialize();
	$data = Git::getTypeName($this->type).' '.strlen($data)."\0".$data;
	fwrite($f, gzcompress($data));
	fclose($f);
	return TRUE;
    }
}

