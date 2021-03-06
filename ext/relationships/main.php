<?php

class ImageRelationshipSetEvent extends Event
{
    public $child_id;
    public $parent_id;


    public function __construct(int $child_id, int $parent_id)
    {
        $this->child_id = $child_id;
        $this->parent_id = $parent_id;
    }
}


class Relationships extends Extension
{
    public const NAME = "Relationships";

    public function onDatabaseUpgrade(DatabaseUpgradeEvent $event)
    {
        global $config, $database;

        // Create the database tables
        if ($this->get_version("ext_relationships_version") < 1) {
            $database->execute("ALTER TABLE images ADD parent_id INT");
            $database->execute($database->scoreql_to_sql("ALTER TABLE images ADD has_children SCORE_BOOL DEFAULT SCORE_BOOL_N NOT NULL"));
            $database->execute("CREATE INDEX images__parent_id ON images(parent_id)");

            $this->set_version("ext_relationships_version", 1);
        }
        if ($this->get_version("ext_relationships_version") < 2) {
            $database->execute("CREATE INDEX images__has_children ON images(has_children)");

            $this->set_version("ext_relationships_version", 2);
        }
    }

    public function onImageInfoSet(ImageInfoSetEvent $event)
    {
        if (isset($_POST['tag_edit__tags']) ? !preg_match('/parent[=|:]/', $_POST["tag_edit__tags"]) : true) { //Ignore tag_edit__parent if tags contain parent metatag
            if (isset($_POST["tag_edit__parent"]) ? ctype_digit($_POST["tag_edit__parent"]) : false) {
                send_event(new ImageRelationshipSetEvent($event->image->id, (int) $_POST["tag_edit__parent"]));
            } else {
                $this->remove_parent($event->image->id);
            }
        }
    }

    public function onDisplayingImage(DisplayingImageEvent $event)
    {
        $this->theme->relationship_info($event->image);
    }

    public function onSearchTermParse(SearchTermParseEvent $event)
    {
        $matches = [];
        if (preg_match("/^parent[=|:]([0-9]+|any|none)$/", $event->term, $matches)) {
            $parentID = $matches[1];

            if (preg_match("/^(any|none)$/", $parentID)) {
                $not = ($parentID == "any" ? "NOT" : "");
                $event->add_querylet(new Querylet("images.parent_id IS $not NULL"));
            } else {
                $event->add_querylet(new Querylet("images.parent_id = :pid", ["pid"=>$parentID]));
            }
        } elseif (preg_match("/^child[=|:](any|none)$/", $event->term, $matches)) {
            $not = ($matches[1] == "any" ? "=" : "!=");
            $event->add_querylet(new Querylet("images.has_children $not TRUE"));
        }
    }

    public function onHelpPageBuilding(HelpPageBuildingEvent $event)
    {
        if ($event->key===HelpPages::SEARCH) {
            $block = new Block();
            $block->header = "Relationships";
            $block->body = $this->theme->get_help_html();
            $event->add_block($block);
        }
    }


    public function onTagTermParse(TagTermParseEvent $event)
    {
        $matches = [];

        if (preg_match("/^parent[=|:]([0-9]+|none)$/", $event->term, $matches) && $event->parse) {
            $parentID = $matches[1];

            if ($parentID == "none" || $parentID == "0") {
                $this->remove_parent($event->id);
            } else {
                send_event(new ImageRelationshipSetEvent($event->id, $parentID));
            }
        } elseif (preg_match("/^child[=|:]([0-9]+)$/", $event->term, $matches) && $event->parse) {
            $childID = $matches[1];

            send_event(new ImageRelationshipSetEvent($childID, $event->id));
        }

        if (!empty($matches)) {
            $event->metatag = true;
        }
    }

    public function onImageInfoBoxBuilding(ImageInfoBoxBuildingEvent $event)
    {
        $event->add_part($this->theme->get_parent_editor_html($event->image), 45);
    }

    public function onImageDeletion(ImageDeletionEvent $event)
    {
        global $database;

        if (bool_escape($event->image->has_children)) {
            $database->execute("UPDATE images SET parent_id = NULL WHERE parent_id = :iid", ["iid"=>$event->image->id]);
        }

        if ($event->image->parent_id !== null) {
            $this->set_has_children($event->image->parent_id);
        }
    }

    public function onImageRelationshipSet(ImageRelationshipSetEvent $event)
    {
        global $database;

        $old_parent = $database->get_one("SELECT parent_id FROM images WHERE id = :cid", ["cid"=>$event->child_id]);

        if ($old_parent!=$event->parent_id) {
            if ($database->get_row("SELECT 1 FROM images WHERE id = :pid", ["pid" => $event->parent_id])) {
                $result = $database->execute("UPDATE images SET parent_id = :pid WHERE id = :cid", ["pid" => $event->parent_id, "cid" => $event->child_id]);

                if ($result->rowCount() > 0) {
                    $database->execute("UPDATE images SET has_children = TRUE WHERE id = :pid", ["pid" => $event->parent_id]);

                    if ($old_parent!=null) {
                        $this->set_has_children($old_parent);
                    }
                }
            }
        }
    }


    public static function get_children(Image $image, int $omit = null): array
    {
        global $database;
        $results = $database->get_all_iterable("SELECT * FROM images WHERE parent_id = :pid ", ["pid"=>$image->id]);
        $output = [];
        foreach ($results as $result) {
            if ($result["id"]==$omit) {
                continue;
            }
            $output[] = new Image($result);
        }
        return $output;
    }

    private function remove_parent(int $imageID)
    {
        global $database;
        $parentID = $database->get_one("SELECT parent_id FROM images WHERE id = :iid", ["iid"=>$imageID]);

        if ($parentID) {
            $database->execute("UPDATE images SET parent_id = NULL WHERE id = :iid", ["iid"=>$imageID]);
            $this->set_has_children($parentID);
        }
    }

    private function set_has_children(int $parent_id)
    {
        global $database;

        // Doesn't work on pgsql
//        $database->execute("UPDATE images SET has_children = (SELECT * FROM (SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END FROM images WHERE parent_id = :pid) AS sub)
        //								WHERE id = :pid", ["pid"=>$parentID]);

        $database->execute(
            "UPDATE images SET has_children = EXISTS (SELECT 1 FROM images WHERE parent_id = :pid) WHERE id = :pid",
            ["pid"=>$parent_id]
        );
    }
}
