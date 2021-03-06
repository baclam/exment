<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Form\Field;

class Editor extends CustomItem
{
    public function html()
    {
        $text = $this->text();
        if (is_null($text)) {
            return null;
        }
        return '<div class="show-tinymce">'.preg_replace("/\\\\r\\\\n|\\\\r|\\\\n|\\r\\n|\\r|\\n/", "<br/>", esc_script_tag($text)).'</div>';
    }
    
    protected function getAdminFieldClass()
    {
        return Field\Tinymce::class;
    }
    
    protected function setAdminOptions(&$field, $form_column_options)
    {
        $options = $this->custom_column->options;
        $field->rows(array_get($options, 'rows', 6));
    }
}
