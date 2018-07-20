<?php

namespace SilbinaryWolf\Components;

use SilverStripe\View\SSViewer;
use SilverStripe\View\SSViewer_Scope;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\View\SSTemplateParser;
use SilverStripe\View\SSTemplateParseException;
use SilverStripe\View\ArrayData;
use SilverStripe\ORM\SS_List;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBString;
use SilverStripe\Core\Config\Config;

class ComponentService
{
    private static $component_paths = [
        'components'
    ];

    /**
     * @param array            $res
     * @param SSTemplateParser $parser
     * @return string The PHP code to insert into the cached SS template file
     */
    public function generateTemplateCode(array $res, $parser)
    {
        $thisClassIdent = self::class.'::class';

        $componentName = $res['ComponentName']['text'];
        $arguments = array();
        $resArguments = isset($res['arguments']) ? $res['arguments'] : array();
        foreach ($resArguments as $i => $phpOutput) {
            // Extract property / values from parser information
            $propertyAndValue = explode('=>', $phpOutput);
            $propertyName = trim($propertyAndValue[0]);
            $valueParts = $propertyAndValue[1];

            $propertyName = trim($propertyName, '\'');
            $propertyName = trim($propertyName, '"');

            if (strpos($valueParts, '<% if') !== false) {
                // NOTE(Jake): 2018-03-31
                //
                // This could be improved to figure out the exact line number of
                // this error. However I think the below message is reasonable
                // enough to debug.
                //
                throw new SSTemplateParseException('Missing < % end_if % > inside property "'.$propertyName.'" on component "'.$componentName.'"', $parser);
            }
            if (strpos($valueParts, '<% loop') !== false) {
                throw new SSTemplateParseException('Cannot use < % loop % > inside property "'.$propertyName.'" on component "'.$componentName.'"', $parser);
            }

            // Modify provided PHP code
            $ifValueParts = explode('[_CPB]', $valueParts);
            $phpCodeValueParts = array();
            foreach ($ifValueParts as $value) {
                $value = trim($value);
                // NOTE(Jake): 2018-04-29
                //
                // Remove blank string concats from template. This is to avoid an object from
                // being cast to a string, like getAttributesHTML().
                //
                // We want to avoid this so that in SS4, there attributes aren't double quoted as
                // HTML is escaped by default.
                //
                $valueParts = explode('[_CFP]', $value);
                foreach ($valueParts as $valuePart) {
                    if ($valuePart === "''") {
                        // Skip empty string parts
                        continue;
                    }
                    $valuePart = trim($valuePart);
                    if (strpos($valuePart, '$val .=') !== false) {
                        if (strpos($valuePart, 'XML_val(') !== false) {
                            // NOTE(Jake): 2018-04-29, This hack is for inside an <% if %>
                            $valuePart = str_replace(array('XML_val(', ';'), array('obj(', '->self();'), $valuePart);
                        }
                        $valuePart = str_replace('$val .=', '$_props[\''.$propertyName.'\'][] =', $valuePart);
                        $phpCodeValueParts[] = $valuePart;
                        //$phpCode .= $valuePart."\n";
                        continue;
                    }
                    $valuePart = "\$_props['".$propertyName."'][] = ".$valuePart.";";
                    $phpCodeValueParts[] = $valuePart;
                    //$phpCode .= $valuePart."\n";
                }
            }

            $phpCode = "";
            if (count($phpCodeValueParts) === 1) {
                //$phpCode .= "\$_props['".$propertyName."'] = \$_props['".$propertyName."'][0];\n";
                $phpCode = "\$_props['".$propertyName."'] = array();\n";
                $phpCode .= $phpCodeValueParts[0]."\n";
                //$phpCode .= "\$_props['".$propertyName."'] = \$_props['".$propertyName."'][0];\n";
            } else {
                $phpCode = "\$_props['".$propertyName."'] = array();\n";
                foreach ($phpCodeValueParts as $phpCodeValuePart) {
                    $phpCode .= $phpCodeValuePart."\n";
                }
                //$phpCode .= "\$_props['".$propertyName."'] = Injector::inst()->createWithArgs('SilbinaryWolf\\Components\\DBComponentField', array('".$propertyName."', \$_props['".$propertyName."']));\n";
            }
            $phpCode .= "\$_props['".$propertyName."'] = \SilverStripe\Core\Injector\Injector::inst()->get({$thisClassIdent})->createProperty('".$propertyName."', \$_props['".$propertyName."']);\n";
            $arguments[$propertyName] = $phpCode;
        }

        // Output "children" php code
        $value = isset($res['Children']['php']) ? $res['Children']['php'] : '';
        if ($value) {
            if (isset($arguments['children'])) {
                throw new SSTemplateParseException('Cannot use "children" as a property name and have inner HTML.', $parser);
            }
            $value = "\$_props['children'] = '';\n".$value;
            $value = str_replace("\$val .=", "\$_props['children'] .=", $value);
            $value .= "\$_props['children'] = \SilverStripe\ORM\FieldType\DBField::create_field('HTMLFragment', \$_props['children']);\n";
            $arguments['children'] = $value;
        }

        // Output PHP code for setting properties
        $result = "\$_props = array();\n";
        foreach ($arguments as $propertyName => $phpCode) {
            $result .= $phpCode;
        }
        $result .= <<<PHP
\$val .= \SilverStripe\Core\Injector\Injector::inst()->get({$thisClassIdent})->renderComponent('$componentName', \$_props, \$scope);
unset(\$_props);
PHP;
        return $result;
    }

    /**
     * @return DBComponentField|SS_List|DataObject|ArrayData
     */
    public function createProperty($name, array $parts)
    {
        // NOTE(Jake): 2018-05-11
        //
        // If in a component, only 1 variable is passed, like so:
        // - <:Comp attribute="$AListVariable">
        //
        // This is so you can use DataLists in a component or the like.
        //
        // The 'instanceof' checks are only really necessary to cast patch "getAttributesHTML"
        // in SilverStripe 3.X. They can probably be removed in favour of just returning $parts[0]
        // in SilverStripe 4.X.
        //
        if (count($parts) === 1) {
            if (!($parts[0] instanceof DBString)) {
                return $parts[0];
            }
        }
        return Injector::inst()->createWithArgs(DBComponentField::class, array($name, $parts));
    }

    /**
     * Render a component
     *
     * @param  string         $name
     * @param  array          $props
     * @param  SSViewer_Scope $scope
     * @return DBHTMLText
     */
    public function renderComponent($name, array $props, SSViewer_Scope $scope)
    {
        $templates = [];
        foreach (Config::inst()->get(__CLASS__, 'component_paths') as $path) {
            $templates[] = ['type' => $path, $name];
        }

        // hardcoded default locations that need to come after the configurable
        // ones defined above
        $templates[] = ["type" => "Includes", $name];
        $templates[] = $name;

        $result = Injector::inst()->createWithArgs(SSViewer::class, [$templates]);
        $data = new ComponentData($name, $props);
        $result = $result->process($data);
        // todo(Jake): 2018-03-31
        //
        // Perhaps make this class extend "Object" and add an extension point.
        // $this->extend('updateRenderComponent', $result, $name, $props, $scope);
        //
        return $result;
    }
}
