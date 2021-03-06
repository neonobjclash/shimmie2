<?php

class ApprovalTheme extends Themelet
{
    public function get_image_admin_html(Image $image)
    {
        if ($image->approved===true) {
            $html = "
			".make_form(make_link('disapprove_image/'.$image->id), 'POST')."
				<input type='hidden' name='image_id' value='$image->id'>
				<input type='submit' value='Disapprove'>
			</form>
		";
        } else {
            $html = "
			".make_form(make_link('approve_image/'.$image->id), 'POST')."
				<input type='hidden' name='image_id' value='$image->id'>
				<input type='submit' value='Approve'>
			</form>
		";
        }

        return $html;
    }


    public function get_help_html()
    {
        return '<p>Search for images that are approved/not approved.</p>
        <div class="command_example">
        <pre>approved:yes</pre>
        <p>Returns images that have been approved.</p>
        </div> 
        <div class="command_example">
        <pre>approved:no</pre>
        <p>Returns images that have not been approved.</p>
        </div> 
        ';
    }

    public function display_admin_block(SetupBuildingEvent $event)
    {
        $sb = new SetupBlock("Approval");
        $sb->add_bool_option(ApprovalConfig::IMAGES, "Images: ");
        $event->panel->add_block($sb);
    }

    public function display_admin_form()
    {
        global $page;

        $html = make_form(make_link("admin/approval"), "POST");
        $html .= "<button name='approval_action' value='approve_all'>Approve All Images</button><br/>";
        $html .= "<button name='approval_action' value='disapprove_all'>Disapprove All Images</button>";
        $html .= "</form>\n";
        $page->add_block(new Block("Approval", $html));
    }
}
