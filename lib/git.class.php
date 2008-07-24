<?php

require_once('git/git_object.class.php');
require_once('git/git_blob.class.php');
require_once('git/git_commit.class.php');
require_once('git/git_commit_stamp.class.php');
require_once('git/git_tree.class.php');

function sha1_bin($hex)
{
    return pack('H40', $hex);
}

function sha1_hex($bin)
{
    return bin2hex($bin);
}

class Git
{
    public $dir;

    const OBJ_NONE = 0;
    const OBJ_COMMIT = 1;
    const OBJ_TREE = 2;
    const OBJ_BLOB = 3;
    const OBJ_TAG = 4;
    const OBJ_OFS_DELTA = 6;
    const OBJ_REF_DELTA = 7;

    static public function getTypeID($name)
    {
	if ($name == 'commit')
	    return Git::OBJ_COMMIT;
	else if ($name == 'tree')
	    return Git::OBJ_TREE;
	else if ($name == 'blob')
	    return Git::OBJ_BLOB;
	else if ($name == 'tag')
	    return Git::OBJ_TAG;
	throw new Exception(sprintf('unknown type name: %s', $name));
    }

    static public function getTypeName($type)
    {
	if ($type == Git::OBJ_COMMIT)
	    return 'commit';
	else if ($type == Git::OBJ_TREE)
	    return 'tree';
	else if ($type == Git::OBJ_BLOB)
	    return 'blob';
	else if ($type == Git::OBJ_TAG)
	    return 'tag';
	throw new Exception(sprintf('no string representation of type %d', $type));
    }

    public function __construct($dir)
    {
	$this->dir = $dir;

	$this->packs = array();
	$dh = opendir(sprintf('%s/objects/pack', $this->dir));
	while (($entry = readdir($dh)) !== FALSE)
	    if (preg_match('#^pack-([0-9a-fA-F]{40})\.idx$#', $entry, $m))
		array_push($this->packs, sha1_bin($m[1]));
    }

    protected function getRawObject($object_name)
    {
	$sha1 = sha1_hex($object_name);
	$path = sprintf('%s/objects/%s/%s', $this->dir, substr($sha1, 0, 2), substr($sha1, 2));
	if (file_exists($path))
	{
            list($hdr, $object_data) = explode("\0", gzuncompress(file_get_contents($path)), 2);

	    sscanf($hdr, "%s %d", $type, $object_size);
	    $object_type = Git::getTypeID($type);
	}
	else
	{
	    /* look into packs */
	    foreach ($this->packs as $pack_name)
	    {
		$index = fopen(sprintf('%s/objects/pack/pack-%s.idx', $this->dir, sha1_hex($pack_name)), 'rb');
		flock($index, LOCK_SH);
		$object_offset = -1;
		/* check version */
		$magic = fread($index, 4);
		if ($magic == "\xFFtOc")
		{
		    /* version 2+ */
		    throw new Exception('unsupported pack index format');
		}
		else
		{
		    /* version 1 */
		    /* read corresponding fanout entry */
		    fseek($index, max(ord($object_name{0})-1, 0)*4);
		    list($prev, $cur) = array_merge(unpack('N2', fread($index, 8)));
		    $n = (ord($object_name{0}) == 0 ? $prev : $cur-$prev);
		    if ($n > 0)
		    {
			/*
			 * TODO: do a binary search in [$offset, $offset+24*$n)
			 */
			fseek($index, 4*256 + 24*$prev);
			for ($i = 0; $i < $n; $i++)
			{
			    $a = unpack('Noff/a20name', fread($index, 24));
			    if ($a['name'] == $object_name)
			    {
				/* we found the object */
				$object_offset = $a['off'];
				break;
			    }
			}
		    }
		}
		fclose($index);
		if ($object_offset != -1)
		{
		    $pack = fopen(sprintf('%s/objects/pack/pack-%s.pack', $this->dir, sha1_hex($pack_name)), 'rb');
		    flock($pack, LOCK_SH);
		    $magic = fread($pack, 4);
		    list($version) = array_merge(unpack('N', fread($pack, 4)));
		    if ($magic != 'PACK' || $version != 2)
			throw new Exception('unsupported pack format');
		    fseek($pack, $object_offset);
		    $c = ord(fgetc($pack));
		    $type = ($c >> 4) & 0x07;
		    $size = $c & 0x0F;
		    for ($i = 4; $c & 0x80; $i += 7)
		    {
			$c = ord(fgetc($pack));
			$size |= ($c << $i);
		    }
		    /* compare sha1_file.c:1608 unpack_entry */
		    if ($type == Git::OBJ_COMMIT || $type == Git::OBJ_TREE || $type == Git::OBJ_BLOB || $type == Git::OBJ_TAG)
		    {
			$object_type = $type;
			$object_size = $size;

                        /*
                         * We don't know the actual size of the compressed
                         * data, so we'll assume it's less than
                         * $object_size+512.
                         */
                        $object_data = gzuncompress(fread($pack, $object_size+512), $object_size);
		    }
		    else if ($type == Git::OBJ_OFS_DELTA || $type == Git::OBJ_REF_DELTA)
		    {
			if ($type == Git::OBJ_REF_DELTA)
			{
			    $base_name = fread($pack, 20);
			    list($object_type, $base) = $this->getRawObject($base_name);

                            // $size is the length of the uncompressed delta
			    $delta = gzuncompress(fread($pack, $size+512), $size);
                            $pos = 0;

			    $base_size = 0;
			    $c = 0x80;
			    for ($i = 0; $c & 0x80; $i += 7)
			    {
				$c = ord($delta{$pos++});
				$base_size |= ($c << $i);
			    }

			    $object_size = 0;
			    $c = 0x80;
			    for ($i = 0; $c & 0x80; $i += 7)
			    {
				$c = ord($delta{$pos++});
				$object_size |= ($c << $i);
			    }

//			    assert($base_size == strlen($base));

			    $object_data = '';
			    while ($pos < strlen($delta))
			    {
				$opcode = ord($delta{$pos++});
				if ($opcode & 0x80)
				{
				    $off = 0;
				    if ($opcode & 0x01) $off = ord($delta{$pos++});
				    if ($opcode & 0x02) $off |= ord($delta{$pos++}) <<  8;
				    if ($opcode & 0x04) $off |= ord($delta{$pos++}) << 16;
				    if ($opcode & 0x08) $off |= ord($delta{$pos++}) << 16;
				    $len = 0;
				    if ($opcode & 0x10) $len = ord($delta{$pos++});
				    if ($opcode & 0x20) $len |= ord($delta{$pos++}) <<  8;
				    if ($opcode & 0x40) $len |= ord($delta{$pos++}) << 16;
				    $object_data .= substr($base, $off, $len);
				}
				else
                                {
				    $object_data .= substr($delta, $pos, $opcode);
                                    $pos += $opcode;
                                }
			    }
			}
			else
			    throw new Exception('offset deltas are not yet supported');
		    }
		    else
			throw new Exception(sprintf('object %s of unknown type %d', sha1_hex($object_name), $type));
		    break;
		    fclose($pack);
		}
	    }
	    if ($object_offset == -1)
		throw new Exception(sprintf('object not found: %s', sha1_hex($object_name)));
	}
//	assert($object_size == strlen($object_data));
	return array($object_type, $object_data);
    }

    public function getObject($name)
    {
	list($type, $data) = $this->getRawObject($name);
	$object = GitObject::create($this, $type);
	$object->unserialize($data);
	assert($name == $object->getName());
	return $object;
    }

    public function getHead($branch)
    {
	$subpath = sprintf('refs/heads/%s', $branch);
	$path = sprintf('%s/%s', $this->dir, $subpath);
	if (file_exists($path))
	    return sha1_bin(file_get_contents($path));
	$path = sprintf('%s/packed-refs', $this->dir);
	if (file_exists($path))
	{
	    $head = NULL;
	    $f = fopen($path, 'r');
	    flock($f, LOCK_SH);
	    while ($head === NULL && ($line = fgets($f)) !== FALSE)
	    {
		if ($line{0} == '#')
		    continue;
		$parts = explode(' ', trim($line));
		if (count($parts) == 2 && $parts[1] == $subpath)
		    $head = sha1_bin($parts[0]);
	    }
	    fclose($f);
	    if ($head !== NULL)
		return $head;
	}
	throw new Exception(sprintf('no such branch: %s', $branch));
    }
}

