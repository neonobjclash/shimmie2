<?php

class ImageAdminBlockBuildingEvent extends Event
{
    /** @var string[] */
    public $parts = [];
    /** @var ?Image  */
    public $image = null;
    /** @var ?User  */
    public $user = null;

    public function __construct(Image $image, User $user)
    {
        $this->image = $image;
        $this->user = $user;
    }

    public function add_part(string $html, int $position=50)
    {
        while (isset($this->parts[$position])) {
            $position++;
        }
        $this->parts[$position] = $html;
    }
}
