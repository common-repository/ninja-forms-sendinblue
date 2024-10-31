<?php
if ( ! defined( 'ABSPATH' ) ) exit;
if ( !class_exists( 'Mailin_ninja' ) )
    require_once 'sendinblue.php';
class NF_Sendinblue_Action extends NF_Abstracts_ActionNewsletter
{
    /**
     * @var string
     */
    protected $_name  = 'sendinblue';

    /**
     * @var array
     */
    protected $_tags = array('newsletter');

    /**
     * @var string
     */
    protected $_timing = 'normal';

    /**
     * @var int
     */
    protected $_priority = 10;

    protected $_api ;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->_nicename = __( 'Sendinblue', 'ninja_forms_sib' );
        $option = get_option( "ninja_forms_settings" );
        $this->_api = new Mailin_ninja("https://api.sendinblue.com/v2.0",$option['ninja_forms_sib_api'] );
        $this->get_list_settings();

        add_action( 'ninja_forms_builder_templates', array($this, 'nf_sib_custom_row_template' ));
    }

    /**
     * get sendinblue contact lists
     * @return array
     */
    public function get_lists(){
        // get lists
        $data = array();
        $option = get_option( "ninja_forms_settings" );
        $account = new Mailin_ninja("https://api.sendinblue.com/v2.0",$option['ninja_forms_sib_api'] );
        $lists = $account->get_lists($data);

        $list_data = array();

        if($lists)
        {
            foreach ($lists['data'] as $list) {
                if($list['name'] == 'Temp - DOUBLE OPTIN'){
                    continue;
                }
                $list_data[] = array(
                    'value'      => $list['id'],
                    'label'      => $list['name'],
                    'fields'     => array(
                        array(
                            'value'     => $list['id'],
                            'label'     => $list['name']
                        )
                    )
                );
            }
            array_unshift( $list_data, array( 'value' => 0, 'label' => __( "Please select matched list", 'ninja_forms_sb' ), 'fields' => array() ));
            return $list_data;
        }
        return array();
    }

    /**
     * get sendinblue contact attributes
     */
    public function get_sib_attributes()
    {
     // get attributes
        $attrs = get_transient($this->get_name()  . '_newsletter_attributes');

        if ($attrs === false || $attrs == false) {
            $mailin = $this->_api;
            $response = $mailin->get_attributes();
            $attributes = $response['data'];

            if (!is_array($attributes)) {
                $attributes = array(
                    'normal_attributes' => array(),
                    'category_attributes' => array(),
                );
            }
            $attrs = array('attributes' => $attributes);
            if (sizeof($attributes) > 0) {
                set_transient($this->get_name()  . '_newsletter_attributes', $attrs, $this->_transient_expiration);
            }
        }

        return $attrs;

    }

    /**
     * get sendinblue lists by ajax
     */
    public function _get_lists()
    {
        check_ajax_referer( 'ninja_forms_builder_nonce', 'security' );

        $lists = $this->get_lists();

        $this->cache_lists( $lists );

        echo wp_json_encode( array( 'lists' => $lists ) );

        wp_die(); // this is required to terminate immediately and return a proper response
    }

    /**
     * Form processing
     *
     * @param $action_settings
     * @param $form_id
     * @param $data
     */
    public function process($action_settings, $form_id, $data)
    {
        $info = array();
        $user_email = '';
        $list_id = $action_settings['newsletter_list'];
        foreach($action_settings['sendinblue_list_attributes'] as $list)
        {
            $info[$list['sib_attr']] = $list['form_field'];
            if($list['sib_attr'] == 'EMAIL')
            {
                $user_email = $list['form_field'];
                unset($info['EMAIL']);
            }
        }
        if($list_id == 0 || $user_email == '' || !is_email($user_email))
            return;
        $this->create_subscriber($user_email, $list_id, $info);
    }

    /**
     * create_subscriber function.
     *
     * @access public
     * @param string $email
     * @param string $list_id
     * @param string $name
     * @return void
     */
    public function create_subscriber($email, $list_id, $info)
    {
        try {

            $account = $this->_api;

            $data = array(
                "email" => $email,
                "attributes" => $info,
                "blacklisted" => 0,
                "listid" => array(intval($list_id)),
                "listid_unlink" => null,
                "blacklisted_sms" => 0
            );
            $response = $account->create_update_user($data);

            return $response['code'];
        } catch (Exception $e) {
            //Authorization is invalid
            //if ($e->type === 'UnauthorizedError')
            //$this->deauthorize();
        }
    } // End create_subscriber()

    /**
     * @param $action_settings
     */
    public function save($action_settings)
    {
        parent::save($action_settings); 
    }

    /**
     * Custom template for option-repeater
     */
    public function nf_sib_custom_row_template()
    {
        $sib_attributes = $this->get_sib_attributes();
        $sibAttrs = $sib_attributes['attributes']['normal_attributes'];
        ?>
        <script id="nf-tmpl-sib-attribute-match-row" type="text/template">

            <div>
                <span class="dashicons dashicons-menu handle"></span>
            </div>
            <div class="nf-select nf_sib_matched_lists">
                <select class="setting" data-id="sib_attr" style="width: 100%; background-color: #F9F9F9">
                    <option >Please Select Sendinblue Attributes</option>
                    <option value="EMAIL" {{{ (data.sib_attr == "EMAIL") ? 'selected="selected"' : ''}}} > EMAIL </option>
                    <?php foreach ($sibAttrs as $attr): ?>
                        <option value="<?php echo $attr['name']; ?>" {{{ data.sib_attr == "<?php echo $attr['name']?>" ? 'selected="selected"' : ''}}} > <?php echo $attr['name']; ?> </option>
                    <?php endforeach; ?>
                </select>
                <span class="nf-option-error"></span>
            </div>
            <div class="has-merge-tags">
                <input type="text" class="setting" data-id="form_field" value="{{{ data.form_field }}}" placeholder="Select Form Fields"/>
                <span class="dashicons dashicons-list-view merge-tags"></span>
            </div>
            <div>
                <span class="dashicons dashicons-dismiss nf-delete"></span>
            </div>
        </script>

        <?php

    }

    /**
     * set transient for sendinblue lists
     *
     * @param $lists
     */
    private function cache_lists( $lists )
    {
        set_transient( $this->_transient, $lists, $this->_transient_expiration );
    }

    /**
     * add sendinblue settings section
     */
    private function get_list_settings()
    {
        $label_defaults = array(
            'list'   => 'List',
        );

        $labels = array_merge( $label_defaults, $this->_setting_labels );

        $prefix = $this->get_name();

        $lists = get_transient( $this->_transient );

        if( ! $lists ) {
            $lists = $this->get_lists();
            $this->cache_lists( $lists );
        }

        if( empty( $lists ) ) return;
        unset($this->_settings[ $prefix . 'newsletter_list_groups' ]);
        unset($this->_settings[ $prefix . 'newsletter_list_fields']);
        $this->_settings[ $prefix . 'newsletter_list' ] = array(
            'name' => 'newsletter_list',
            'type' => 'select',
            'label' => $labels[ 'list' ] . ' <a class="js-newsletter-list-update extra"><span class="dashicons dashicons-update"></span></a>',
            'width' => 'full',
            'group' => 'primary',
            'value' => '0',
            'options' => array(),
        );

        foreach( $lists as $list ){
            $this->_settings[ $prefix . 'newsletter_list' ][ 'options' ][] = $list;
        }

        $this->_settings[ $prefix . 'sendinblue_list_attributes' ] = array(
            'name'              => 'sendinblue_list_attributes',
            'type'              => 'option-repeater',
            'label'             => __( 'SendinBlue Contact Attributes', 'ninja_forms_sib' ) . ' <a href="#" class="nf-add-new">' . __( 'Add New', 'ninja_forms_sib' ) . '</a>',
            'width'             => 'full',
            'group'             => 'advanced',

            'columns'          => array(
                'sib_attr'         => array(
                    'header'    => __( 'Sendinblue Attributes', 'ninja_forms_sib' ),
                    'default'   => '',
                ),

                'form_field'         => array(
                    'header'    => __( 'Form Fields', 'ninja_forms_sib' ),
                    'default'   => '',
                ),
            ),
            'tmpl_row'          => 'nf-tmpl-sib-attribute-match-row',
        );

    }
}