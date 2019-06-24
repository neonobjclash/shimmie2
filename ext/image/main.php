<?php
/*
 * Name: Image Manager
 * Author: Shish <webmaster@shishnet.org>
 * Modified by: jgen <jgen.tech@gmail.com>
 * Link: http://code.shishnet.org/shimmie2/
 * Description: Handle the image database
 * Visibility: admin
 */


/**
 * A class to handle adding / getting / removing image files from the disk.
 */
class ImageIO extends Extension
{
    public function onInitExt(InitExtEvent $event)
    {
        global $config;
        $config->set_default_int('thumb_width', 192);
        $config->set_default_int('thumb_height', 192);
        $config->set_default_int('thumb_scaling', 100);
        $config->set_default_int('thumb_quality', 75);
        $config->set_default_string('thumb_type', 'jpg');
        $config->set_default_int('thumb_mem_limit', parse_shorthand_int('8MB'));
        $config->set_default_string('thumb_convert_path', 'convert');

        if (function_exists("exif_read_data")) {
            $config->set_default_bool('image_show_meta', false);
        }
        $config->set_default_string('image_ilink', '');
        $config->set_default_string('image_tlink', '');
        $config->set_default_string('image_tip', '$tags // $size // $filesize');
        $config->set_default_string('upload_collision_handler', 'error');
        $config->set_default_int('image_expires', (60*60*24*31));	// defaults to one month
    }

    public function onPageRequest(PageRequestEvent $event)
    {
        if ($event->page_matches("image/delete")) {
            global $page, $user;
            if ($user->can("delete_image") && isset($_POST['image_id']) && $user->check_auth_token()) {
                $image = Image::by_id($_POST['image_id']);
                if ($image) {
                    send_event(new ImageDeletionEvent($image));
                    $page->set_mode(PageMode::REDIRECT);
                    if (isset($_SERVER['HTTP_REFERER']) && !strstr($_SERVER['HTTP_REFERER'], 'post/view')) {
                        $page->set_redirect($_SERVER['HTTP_REFERER']);
                    } else {
                        $page->set_redirect(make_link("post/list"));
                    }
                }
            }
        } elseif ($event->page_matches("image/replace")) {
            global $page, $user;
            if ($user->can("replace_image") && isset($_POST['image_id']) && $user->check_auth_token()) {
                $image = Image::by_id($_POST['image_id']);
                if ($image) {
                    $page->set_mode(PageMode::REDIRECT);
                    $page->set_redirect(make_link('upload/replace/'.$image->id));
                } else {
                    /* Invalid image ID */
                    throw new ImageReplaceException("Image to replace does not exist.");
                }
            }
        } elseif ($event->page_matches("image")) {
            $num = int_escape($event->get_arg(0));
            $this->send_file($num, "image");
        } elseif ($event->page_matches("thumb")) {
            $num = int_escape($event->get_arg(0));
            $this->send_file($num, "thumb");
        }
    }

    public function onImageAdminBlockBuilding(ImageAdminBlockBuildingEvent $event)
    {
        global $user;
        
        if ($user->can("delete_image")) {
            $event->add_part($this->theme->get_deleter_html($event->image->id));
        }
        /* In the future, could perhaps allow users to replace images that they own as well... */
        if ($user->can("replace_image")) {
            $event->add_part($this->theme->get_replace_html($event->image->id));
        }
    }

    public function onImageAddition(ImageAdditionEvent $event)
    {
        try {
            $this->add_image($event);
        } catch (ImageAdditionException $e) {
            throw new UploadException($e->error);
        }
    }

    public function onImageDeletion(ImageDeletionEvent $event)
    {
        $event->image->delete();
    }

    public function onImageReplace(ImageReplaceEvent $event)
    {
        try {
            $this->replace_image($event->id, $event->image);
        } catch (ImageReplaceException $e) {
            throw new UploadException($e->error);
        }
    }
    
    public function onUserPageBuilding(UserPageBuildingEvent $event)
    {
        $u_id = url_escape($event->display_user->id);
        $i_image_count = Image::count_images(["user_id={$event->display_user->id}"]);
        $i_days_old = ((time() - strtotime($event->display_user->join_date)) / 86400) + 1;
        $h_image_rate = sprintf("%.1f", ($i_image_count / $i_days_old));
        $images_link = make_link("post/list/user_id=$u_id/1");
        $event->add_stats("<a href='$images_link'>Images uploaded</a>: $i_image_count, $h_image_rate per day");
    }

    public function onSetupBuilding(SetupBuildingEvent $event)
    {
        global $config;

        $sb = new SetupBlock("Image Options");
        $sb->position = 30;
        // advanced only
        //$sb->add_text_option("image_ilink", "Image link: ");
        //$sb->add_text_option("image_tlink", "<br>Thumbnail link: ");
        $sb->add_text_option("image_tip", "Image tooltip: ");
        $sb->add_choice_option("upload_collision_handler", ['Error'=>'error', 'Merge'=>'merge'], "<br>Upload collision handler: ");
        if (function_exists("exif_read_data")) {
            $sb->add_bool_option("image_show_meta", "<br>Show metadata: ");
        }

        $event->panel->add_block($sb);

        $thumbers = [];
        $thumbers['Built-in GD'] = "gd";
        $thumbers['ImageMagick'] = "convert";

        $thumb_types = [];
        $thumb_types['JPEG'] = "jpg";
        $thumb_types['WEBP (Not IE/Safari compatible)'] = "webp";


        $sb = new SetupBlock("Thumbnailing");
        $sb->add_choice_option("thumb_engine", $thumbers, "Engine: ");
        $sb->add_label("<br>");
        $sb->add_choice_option("thumb_type", $thumb_types, "Filetype: ");

        $sb->add_label("<br>Size ");
        $sb->add_int_option("thumb_width");
        $sb->add_label(" x ");
        $sb->add_int_option("thumb_height");
        $sb->add_label(" px at ");
        $sb->add_int_option("thumb_quality");
        $sb->add_label(" % quality ");

        $sb->add_label("<br>High-DPI scaling ");
        $sb->add_int_option("thumb_scaling");
        $sb->add_label("%");

        if ($config->get_string("thumb_engine") == "convert") {
            $sb->add_label("<br>ImageMagick Binary: ");
            $sb->add_text_option("thumb_convert_path");
        }

        if ($config->get_string("thumb_engine") == "gd") {
            $sb->add_shorthand_int_option("thumb_mem_limit", "<br>Max memory use: ");
        }

        $event->panel->add_block($sb);
    }


    // add image {{{
    private function add_image(ImageAdditionEvent $event)
    {
        global $user, $database, $config;

        $image = $event->image;

        /*
         * Validate things
         */
        if (strlen(trim($image->source)) == 0) {
            $image->source = null;
        }

        /*
         * Check for an existing image
         */
        $existing = Image::by_hash($image->hash);
        if (!is_null($existing)) {
            $handler = $config->get_string("upload_collision_handler");
            if ($handler == "merge" || isset($_GET['update'])) {
                $merged = array_merge($image->get_tag_array(), $existing->get_tag_array());
                send_event(new TagSetEvent($existing, $merged));
                if (isset($_GET['rating']) && isset($_GET['update']) && ext_is_live("Ratings")) {
                    send_event(new RatingSetEvent($existing, $_GET['rating']));
                }
                if (isset($_GET['source']) && isset($_GET['update'])) {
                    send_event(new SourceSetEvent($existing, $_GET['source']));
                }
                $event->merged = true;
                $event->image = Image::by_id($existing->id);
                return;
            } else {
                $error = "Image <a href='".make_link("post/view/{$existing->id}")."'>{$existing->id}</a> ".
                        "already has hash {$image->hash}:<p>".$this->theme->build_thumb_html($existing);
                throw new ImageAdditionException($error);
            }
        }

        // actually insert the info
        $database->Execute(
            "INSERT INTO images(
					owner_id, owner_ip, filename, filesize,
					hash, ext, width, height, posted, source
				)
				VALUES (
					:owner_id, :owner_ip, :filename, :filesize,
					:hash, :ext, :width, :height, now(), :source
				)",
            [
                "owner_id" => $user->id, "owner_ip" => $_SERVER['REMOTE_ADDR'], "filename" => substr($image->filename, 0, 255), "filesize" => $image->filesize,
                    "hash"=>$image->hash, "ext"=>strtolower($image->ext), "width"=>$image->width, "height"=>$image->height, "source"=>$image->source
                ]
        );
        $image->id = $database->get_last_insert_id('images_id_seq');

        log_info("image", "Uploaded Image #{$image->id} ({$image->hash})");

        # at this point in time, the image's tags haven't really been set,
        # and so, having $image->tag_array set to something is a lie (but
        # a useful one, as we want to know what the tags are /supposed/ to
        # be). Here we correct the lie, by first nullifying the wrong tags
        # then using the standard mechanism to set them properly.
        $tags_to_set = $image->get_tag_array();
        $image->tag_array = [];
        send_event(new TagSetEvent($image, $tags_to_set));

        if ($image->source !== null) {
            log_info("core-image", "Source for Image #{$image->id} set to: {$image->source}");
        }
    }
    // }}}  end add

    // fetch image {{{
    private function send_file(int $image_id, string $type)
    {
        global $config;
        $image = Image::by_id($image_id);

        global $page;
        if (!is_null($image)) {
            if ($type == "thumb") {
                $ext = $config->get_string("thumb_type");
                if (array_key_exists($ext, MIME_TYPE_MAP)) {
                    $page->set_type(MIME_TYPE_MAP[$ext]);
                } else {
                    $page->set_type("image/jpeg");
                }

                $file = $image->get_thumb_filename();
            } else {
                $page->set_type($image->get_mime_type());
                $file = $image->get_image_filename();
            }

            if (isset($_SERVER["HTTP_IF_MODIFIED_SINCE"])) {
                $if_modified_since = preg_replace('/;.*$/', '', $_SERVER["HTTP_IF_MODIFIED_SINCE"]);
            } else {
                $if_modified_since = "";
            }
            $gmdate_mod = gmdate('D, d M Y H:i:s', filemtime($file)) . ' GMT';

            if ($if_modified_since == $gmdate_mod) {
                $page->set_mode(PageMode::DATA);
                $page->set_code(304);
                $page->set_data("");
            } else {
                $page->set_mode(PageMode::FILE);
                $page->add_http_header("Last-Modified: $gmdate_mod");
                if ($type != "thumb") {
                    $page->set_filename($image->get_nice_image_name(), 'inline');
                }

                $page->set_file($file);
                
                if ($config->get_int("image_expires")) {
                    $expires = date(DATE_RFC1123, time() + $config->get_int("image_expires"));
                } else {
                    $expires = 'Fri, 2 Sep 2101 12:42:42 GMT'; // War was beginning
                }
                $page->add_http_header('Expires: ' . $expires);
            }
        } else {
            $page->set_title("Not Found");
            $page->set_heading("Not Found");
            $page->add_block(new Block("Navigation", "<a href='" . make_link() . "'>Index</a>", "left", 0));
            $page->add_block(new Block(
                "Image not in database",
                "The requested image was not found in the database"
            ));
        }
    }
    // }}} end fetch

    // replace image {{{
    private function replace_image(int $id, Image $image)
    {
        global $database;

        /* Check to make sure the image exists. */
        $existing = Image::by_id($id);
        
        if (is_null($existing)) {
            throw new ImageReplaceException("Image to replace does not exist!");
        }

        if (strlen(trim($image->source)) == 0) {
            $image->source = $existing->get_source();
        }
        
        /*
            This step could be optional, ie: perhaps move the image somewhere
            and have it stored in a 'replaced images' list that could be
            inspected later by an admin?
        */

        log_debug("image", "Removing image with hash ".$existing->hash);
        $existing->remove_image_only(); // Actually delete the old image file from disk
        
        // Update the data in the database.
        $database->Execute(
            "UPDATE images SET 
					filename = :filename, filesize = :filesize,	hash = :hash,
					ext = :ext, width = :width, height = :height, source = :source
				WHERE 
					id = :id
				",
            [
                    "filename" => substr($image->filename, 0, 255), "filesize"=>$image->filesize, "hash"=>$image->hash,
                    "ext"=>strtolower($image->ext), "width"=>$image->width, "height"=>$image->height, "source"=>$image->source,
                    "id"=>$id
                ]
        );

        /* Generate new thumbnail */
        send_event(new ThumbnailGenerationEvent($image->hash, strtolower($image->ext)));

        log_info("image", "Replaced Image #{$id} with ({$image->hash})");
    }
    // }}} end replace
} // end of class ImageIO
