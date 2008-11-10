<?php

class GitCommitStamp
{
    public $name;
    public $email;
    public $time;
    public $offset;

    public function unserialize($data)
    {
	assert(preg_match('/^(.+?)\s+<(.+?)>\s+(\d+)\s+([+-]\d{4})$/', $data, $m));
	$this->name = $m[1];
	$this->email = $m[2];
	$this->time = intval($m[3]);
	$off = intval($m[4]);
	$this->offset = ($off/100) * 3600 + ($off%100) * 60;
    }

    public function serialize()
    {
	if ($this->offset%60)
	    throw new Exception('cannot serialize sub-minute timezone offset');
	return sprintf('%s <%s> %d %+05d', $this->name, $this->email, $this->time, ($this->offset/3600)*100 + ($this->offset/60)%60);
    }
};
?>
