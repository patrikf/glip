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
}

