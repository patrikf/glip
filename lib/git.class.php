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
		$this->packs[] = sha1_bin($m[1]);
    }

    protected function readFanout($f, $object_name, $offset)
    {
        if ($object_name{0} == "\x00")
        {
            $cur = 0;
            fseek($f, $offset);
            $after = Binary::fuint32($f);
        }
        else
        {
            fseek($f, $offset + (ord($object_name{0}) - 1)*4);
            $cur = Binary::fuint32($f);
            $after = Binary::fuint32($f);
        }

        return array($cur, $after);
    }

    /**
     * Try to find an object in a pack.
     *
     * @param string $object_name name of the object (binary SHA1)
     * @return array an array consisting of the name of the pack (string) and
     * the byte offset inside it, or NULL if not found
     */
    protected function findPackedObject($object_name)
    {
        foreach ($this->packs as $pack_name)
        {
            $index = fopen(sprintf('%s/objects/pack/pack-%s.idx', $this->dir, sha1_hex($pack_name)), 'rb');
            flock($index, LOCK_SH);

            /* check version */
            $magic = fread($index, 4);
            if ($magic != "\xFFtOc")
            {
                /* version 1 */
                /* read corresponding fanout entry */
                list($cur, $after) = $this->readFanout($index, $object_name, 0);

                $n = $after-$cur;
                if ($n == 0)
                    continue;

                /*
                 * TODO: do a binary search in [$offset, $offset+24*$n)
                 */
                fseek($index, 4*256 + 24*$cur);
                for ($i = 0; $i < $n; $i++)
                {
                    $off = Binary::fuint32($index);
                    $name = fread($index, 20);
                    if ($name == $object_name)
                    {
                        /* we found the object */
                        fclose($index);
                        return array($pack_name, $off);
                    }
                }
            }
            else
            {
                /* version 2+ */
                $version = Binary::fuint32($index);
                if ($version == 2)
                {
                    list($cur, $after) = $this->readFanout($index, $object_name, 8);

                    if ($cur == $after)
                        continue;

                    fseek($index, 8 + 4*255);
                    $total_objects = Binary::fuint32($index);

                    /* look up sha1 */
                    fseek($index, 8 + 4*256 + 20*$cur);
                    for ($i = $cur; $i < $after; $i++)
                    {
                        $name = fread($index, 20);
                        if ($name == $object_name)
                            break;
                    }
                    if ($i == $after)
                        continue;

                    fseek($index, 8 + 4*256 + 24*$total_objects + 4*$i);
                    $off = Binary::fuint32($index);
                    if ($off & 0x80000000)
                    {
                        /* packfile > 2 GB. Gee, you really want to handle this
                         * much data with PHP?
                         */
                        throw new Exception('64-bit packfiles offsets not implemented');
                    }

                    fclose($index);
                    return array($pack_name, $off);
                }
                else
                    throw new Exception('unsupported pack index format');
            }
            fclose($index);
        }
        /* not found */
        return NULL;
    }

    /**
     * Apply the git delta $delta to the byte sequence $base.
     *
     * @param string $delta the delta to apply
     * @param string $base the sequence to patch
     * @return string the patched byte sequence
     */
    protected function applyDelta($delta, $base)
    {
        $pos = 0;

        $base_size = Binary::git_varint($delta, &$pos);
        $result_size = Binary::git_varint($delta, &$pos);

        $r = '';
        while ($pos < strlen($delta))
        {
            $opcode = ord($delta{$pos++});
            if ($opcode & 0x80)
            {
                /* copy a part of $base */
                $off = 0;
                if ($opcode & 0x01) $off = ord($delta{$pos++});
                if ($opcode & 0x02) $off |= ord($delta{$pos++}) <<  8;
                if ($opcode & 0x04) $off |= ord($delta{$pos++}) << 16;
                if ($opcode & 0x08) $off |= ord($delta{$pos++}) << 24;
                $len = 0;
                if ($opcode & 0x10) $len = ord($delta{$pos++});
                if ($opcode & 0x20) $len |= ord($delta{$pos++}) <<  8;
                if ($opcode & 0x40) $len |= ord($delta{$pos++}) << 16;
                $r .= substr($base, $off, $len);
            }
            else
            {
                /* take the next $opcode bytes as they are */
                $r .= substr($delta, $pos, $opcode);
                $pos += $opcode;
            }
        }
        return $r;
    }

    /**
     * Unpack an object from a pack.
     *
     * @param resource $pack open .pack file
     * @param resource $object_offset offset of the object in the pack
     * @return array an array consisting of the object type (int) and the
     * binary representation of the object (string)
     */
    protected function unpackObject($pack, $object_offset)
    {
        fseek($pack, $object_offset);

        /* read object header */
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
            /*
             * We don't know the actual size of the compressed
             * data, so we'll assume it's less than
             * $object_size+512.
             *
             * FIXME use PHP stream filter API as soon as it behaves
             * consistently
             */
            $data = gzuncompress(fread($pack, $size+512), $size);
        }
        else if ($type == Git::OBJ_OFS_DELTA)
        {
            /* 20 = maximum varint length for offset */
            $buf = fread($pack, $size+512+20);

            /*
             * contrary to varints in other places, this one is big endian
             * (and 1 is added each turn)
             * see sha1_file.c (get_delta_base)
             */
            $pos = 0;
            $offset = -1;
            do
            {
                $offset++;
                $c = ord($buf{$pos++});
                $offset = ($offset << 7) + ($c & 0x7F);
            }
            while ($c & 0x80);

            $delta = gzuncompress(substr($buf, $pos), $size);
            unset($buf);

            $base_offset = $object_offset - $offset;
            assert($base_offset >= 0);
            list($type, $base) = $this->unpackObject($pack, $base_offset);

            $data = $this->applyDelta($delta, $base);
        }
        else if ($type == Git::OBJ_REF_DELTA)
        {
            $base_name = fread($pack, 20);
            list($type, $base) = $this->getRawObject($base_name);

            // $size is the length of the uncompressed delta
            $delta = gzuncompress(fread($pack, $size+512), $size);

            $data = $this->applyDelta($delta, $base);
        }
        else
            throw new Exception(sprintf('object of unknown type %d', $type));

        return array($type, $data);
    }

    /**
     * Fetch an object in its binary representation by name.
     * Throws an exception if the object cannot be found.
     *
     * @param string $object_name name of the object (binary SHA1)
     * @return array an array consisting of the object type (int) and the
     * binary representation of the object (string)
     */
    protected function getRawObject($object_name)
    {
        static $cache = array();
        /* FIXME allow limiting the cache to a certain size */

        if (isset($cache[$object_name]))
            return $cache[$object_name];
	$sha1 = sha1_hex($object_name);
	$path = sprintf('%s/objects/%s/%s', $this->dir, substr($sha1, 0, 2), substr($sha1, 2));
	if (file_exists($path))
	{
            list($hdr, $object_data) = explode("\0", gzuncompress(file_get_contents($path)), 2);

	    sscanf($hdr, "%s %d", $type, $object_size);
	    $object_type = Git::getTypeID($type);
            $r = array($object_type, $object_data);
	}
	else if ($x = $this->findPackedObject($object_name))
	{
            list($pack_name, $object_offset) = $x;

            $pack = fopen(sprintf('%s/objects/pack/pack-%s.pack', $this->dir, sha1_hex($pack_name)), 'rb');
            flock($pack, LOCK_SH);

            /* check magic and version */
            $magic = fread($pack, 4);
            $version = Binary::fuint32($pack);
            if ($magic != 'PACK' || $version != 2)
                throw new Exception('unsupported pack format');

            $r = $this->unpackObject($pack, $object_offset);
            fclose($pack);
	}
        else
            throw new Exception(sprintf('object not found: %s', sha1_hex($object_name)));
        $cache[$object_name] = $r;
        return $r;
    }

    /**
     * Fetch an object in its PHP representation.
     *
     * @param string $name name of the object (binary SHA1)
     * @return GitObject the object
     */
    public function getObject($name)
    {
	list($type, $data) = $this->getRawObject($name);
	$object = GitObject::create($this, $type);
	$object->unserialize($data);
	assert($name == $object->getName());
	return $object;
    }

    /**
     * Returns the tip of $branch (binary sha1).
     */
    public function getTip($branch='master')
    {
	$subpath = sprintf('refs/heads/%s', $branch);
	$path = sprintf('%s/%s', $this->dir, $subpath);
	if (file_exists($path))
	    return sha1_bin(file_get_contents($path));
	$path = sprintf('%s/packed-refs', $this->dir);
	if (file_exists($path))
	{
	    $head = NULL;
	    $f = fopen($path, 'rb');
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
};
?>
