<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 * @package   moodlecore
 * @subpackage backup-imscc
 * @copyright 2011 Darko Miletic (dmiletic@moodlerooms.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') or die('Direct access to this script is forbidden.');

class cc11_lti extends entities11 {

    public function generate_node () {

        cc2moodle::log_action('Creating BasicLTI mods');

        $response = '';

        if (!empty(cc2moodle::$instances['instances'][MOODLE_TYPE_LTI])) {
            foreach (cc2moodle::$instances['instances'][MOODLE_TYPE_LTI] as $instance) {
                $response .= $this->create_node_course_modules_mod_basiclti($instance);
            }
        }

        return $response;
    }

    private function create_node_course_modules_mod_basiclti ($instance) {

        $sheet_mod_basiclti = cc112moodle::loadsheet(SHEET_COURSE_SECTIONS_SECTION_MODS_MOD_LTI);

        $topic_data = $this->get_basiclti_data($instance);

        $result = '';
        if (!empty($topic_data)) {

            $find_tags = array('[#mod_instance#]'        ,
                               '[#mod_basiclti_name#]'   ,
                               '[#mod_basiclti_intro#]'  ,
                               '[#mod_basiclti_timec#]'  ,
                               '[#mod_basiclti_timem#]'  ,
                               '[#mod_basiclti_toolurl#]',
                               '[#mod_basiclti_icon#]',
                               '[#mod_basiclti_customparams#]'
                               );

            $replace_values = array($instance['instance'],
                                    $topic_data['title'],
                                    $topic_data['description'],
                                    time(),time(),
                                    $topic_data['launchurl'],
                                    $topic_data['icon'],
                                    $topic_data['customparameters']
                                    );

            $result = str_replace($find_tags, $replace_values, $sheet_mod_basiclti);

        }

        return $result;
    }

    /**
     * This function extracts name/value pairs from the <property> elements in the <custom> element of the <cartridge_basiclti_link>
     * Custom property elements have a 'name' attribute and their value is the text content of the element.
     * Custom params will be returned to the LTI Tool Provider when the LTI link is used.
     * @param type $nodelist    list of property elements in the document
     * @param type $default
     * @return string           Newline separated list of the custom params as name/value pairs
     */
    protected function get_custom_params($nodelist, $default = null) {
        $result = $default;
        if (is_object($nodelist) && ($nodelist->length > 0)) {
            $result = "";
            for ($i = 0; $i< $nodelist->length; $i++) {
                $currentparam = $nodelist->item($i);
                $customparamvalue = $currentparam->nodeValue;
                if($currentparam->hasAttribute('name') && !empty($customparamvalue)) {
                    $custparamname = $currentparam->getAttribute('name');
                    $result .= $custparamname . '=';
                    $result .= htmlspecialchars(trim($customparamvalue), ENT_COMPAT, 'UTF-8', false) . "\n";
                }
            }
        }
        return $result;
    }

    protected function getValue($node, $default = '') {
        $result = $default;
        if (is_object($node) && ($node->length > 0) && !empty($node->item(0)->nodeValue)) {
            $result = htmlspecialchars(trim($node->item(0)->nodeValue), ENT_COMPAT, 'UTF-8', false);
        }
        return $result;
    }

    public function get_basiclti_data($instance) {

        $topic_data = array();

        $basiclti_file = $this->get_external_xml($instance['resource_indentifier']);

        if (!empty($basiclti_file)) {
            $basiclti_file_path = cc2moodle::$path_to_manifest_folder . DIRECTORY_SEPARATOR . $basiclti_file;
            $basiclti_file_dir = dirname($basiclti_file_path);
            $basiclti = $this->load_xml_resource($basiclti_file_path);
            if (!empty($basiclti)) {
                $xpath = cc2moodle::newx_path($basiclti, cc112moodle::$basicltins);
                $topic_title = $this->getValue($xpath->query('/xmlns:cartridge_basiclti_link/blti:title'),'Untitled');
                $blti_description = $this->getValue($xpath->query('/xmlns:cartridge_basiclti_link/blti:description'));
                $launch_url = $this->get_launch_url($xpath);
                $launch_icon = $this->getValue($xpath->query('/xmlns:cartridge_basiclti_link/blti:icon'));
                $tool_raw = $this->getValue($xpath->query('/xmlns:cartridge_basiclti_link/blti:vendor/lticp:code'),null);
                $tool_url = $this->getValue($xpath->query('/xmlns:cartridge_basiclti_link/blti:vendor/lticp:url'),null);
                $tool_desc = $this->getValue($xpath->query('/xmlns:cartridge_basiclti_link/blti:vendor/lticp:description'),null);
                $custom_params = $this->get_custom_params($xpath->query('/xmlns:cartridge_basiclti_link/blti:custom/lticm:property'),null);
                $topic_data['title'      ] = $topic_title;
                $topic_data['description'] = $blti_description;
                $topic_data['launchurl'  ] = $launch_url;
                $topic_data['icon'       ] = $launch_icon;
                $topic_data['orgid'      ] = $tool_raw;
                $topic_data['orgurl'     ] = $tool_url;
                $topic_data['orgdesc'    ] = $tool_desc;
                $topic_data['customparameters'] = $custom_params;
            }
        }

        return $topic_data;
    }
    
    /**
     * This function extracts the most appropriate launch_url from the elements in the <cartridge_basiclti_link>
     * While the current best practice is to have the same https url as the value of both launch_url and secure_launch_url many
     * older cartridges provide a http url in launch_url and a https in secure_launch_url, while some provide a value only in launch_url.
     * This function will preference a https url if available.
     * @param type $xpath    DOMXpath object bound to the cartridge_basiclti_link document
     * @return string        The (https) launch url
     */
    protected function get_launch_url($xpath) {
        $launch_url = $this->getValue($xpath->query('/xmlns:cartridge_basiclti_link/blti:launch_url'));
        if (mb_substr( $launch_url, 0, 5 ) === 'http:'  ) {
            $secure_launch_url = $this->getValue($xpath->query('/xmlns:cartridge_basiclti_link/blti:secure_launch_url'));
            if (mb_substr( $secure_launch_url, 0, 6 ) === 'https:'  ) {
               $launch_url =  $secure_launch_url;
            }
        }
        return $launch_url;
    }

}

