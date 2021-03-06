<?php

/**
 * Class EmoticonList
 */
class EmoticonList extends Extension
{
    public function onPageRequest(PageRequestEvent $event)
    {
        if ($event->page_matches("emote/list")) {
            $this->theme->display_emotes(glob("ext/emoticons/default/*"));
        }
    }
}
