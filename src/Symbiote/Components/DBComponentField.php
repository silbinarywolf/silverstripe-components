<?php

namespace Symbiote\Components;

use Exception;
use SilverStripe\ORM\FieldType\DBText;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\SS_List;
use SilverStripe\View\ViewableData;

class DBComponentField extends DBText
{
   /**
     * Workaround a "bug" in SilverStripe 3.X where
     * attributes aren't cast as HTMLText and don't
     * escape.
     *
     * NOTE:
     * We may re-introduce this feature in SS 4.X
     * if it proves to be useful.
     *
     * @var array
     */
    //private static $text_property_casting = array(
    //    'getAttributesHTML' => true,
    //);

    /**
     * Store the objects passed into a component property.
     *
     * @var ViewableData[]
     */
    protected $fields = array();

    public function __construct($name, array $fields)
    {
        parent::__construct($name);
        $this->fields = $fields;

        // Set value from fields
        $value = '';
        //$textPropertyCasting = $this->config()->text_property_casting;
        foreach ($this->fields as $i => $field) {
            if (!is_object($field)) {
                $value .= $field;
                continue;
            }
            if (!$field->hasMethod('forTemplate')) {
                if ($field instanceof SS_List) {
                    throw new Exception("Cannot use SS_List type with other field parts. (Make sure there are no spaces around the quotes) for component property \"$name\".");
                }
                throw new Exception("Missing forTemplate() on field part #$i for component property \"$name\".");
            }
            //if (get_class($field) === 'Text') {
            //    if ($textPropertyCasting &&
            //        isset($textPropertyCasting[$field->getName()])) {
            //        $value .= $field->getValue();
            //        continue;
            //    }
            //}
            
            // NOTE(Marcus) 2019-02-20
            //
            // Have swapped this to just concating the raw value; it seems unusual to use
            // forTemplate on a field because the result of this is normally formatted differently
            // to the raw data which is very undesirable if the component decides to use the
            // data in a different manner (ie, as a textarea field content)
            $value .= $field->getValue();
        }
        $this->value = $value;
    }

    /**
     * Stop default behaviour, which is escaping to XML.
     *
     * @return string|SS_List
     */
    public function forTemplate()
    {
        return $this->getValue();
    }

    /**
     * (non-PHPdoc)
     *
     * @see DBField::requireField()
     */
    public function requireField()
    {
        throw new Exception('Do not use this as a database field.');
    }

    /**
     * @param DBField $field
     * @return string|null
     */
    /*private function propertyCasting($field)
    {
        if (get_class($field) === 'Text') {
            if ($textPropertyCasting &&
                isset($textPropertyCasting[$field->getName()])) {
                return $field->getValue();
                continue;
            }
        }
        return null;
    }*/
}
