<?php

class ApprovalInfo extends ExtensionInfo
{
    public const KEY = "approval";

    public $key = self::KEY;
    public $name = "Approval";
    public $authors = ["Matthew Barbour"=>"matthew@darkholme.net"];
    public $license = self::LICENSE_WTFPL;
    public $description = "Adds an approval step to the upload/import process.";
    public $db_support = [DatabaseDriver::MYSQL, DatabaseDriver::PGSQL];
}
