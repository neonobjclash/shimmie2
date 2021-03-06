<?php

class CommentListInfo extends ExtensionInfo
{
    public const KEY = "comment";

    public $key = self::KEY;
    public $name = "Image Comments";
    public $url = self::SHIMMIE_URL;
    public $authors = self::SHISH_AUTHOR;
    public $license = self::LICENSE_GPLV2;
    public $description = "Allow users to make comments on images";
    public $documentation = "Formatting is done with the standard formatting API (normally BBCode)";
    public $core = true;
}
