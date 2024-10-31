<?php
/*
Plugin Name: Ninja Forms - SendinBlue
Description: Sign users up for your SendinBlue newsletter when submitting a ninja form.
Version: 3.1.1
Author: Gaun Yun
Author URI: https://www.sendinblue.com/?r=wporg
Contributors: Gaun Yun
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

class NF_Sib_Main
{
	/** check if wp_mail is declared  */
	static $wp_mail_conflict;

	/** Plugin directory path value. set in constructor */
	public static $plugin_dir;
	/**
	 * constructor
	 */
	public function __construct()
	{
		self::$plugin_dir = plugin_dir_path(__FILE__);
		self::$wp_mail_conflict = false;

		add_action('init', array($this, 'ninja_forms_sb_textdomain'));
		add_action('admin_init', array($this, 'ninja_forms_sb_extension_setup_license'));
		add_filter('ninja_forms_plugin_settings_groups', array($this, 'ninja_forms_sib_settings_group'), 10, 1);
		add_filter('ninja_forms_plugin_settings', array($this, 'ninja_forms_sib_plugin_settings'), 10, 1);
		add_filter('ninja_forms_register_actions', array($this, 'Load_ninja_sendinblue_actions'), 10, 1);
		add_filter('ninja_forms_action_email_message', array($this, 'ninja_forms_add_sib_tags'), 10, 3);//  )

		$sib_enabled = get_option('nf_sib_enabled', '');
		$this->option = get_option( "ninja_forms_settings" , array());
		$this->nf_smtp_enalbe = isset($this->option['nf_sib_smtp_enable']) ? $this->option['nf_sib_smtp_enable'] : 'no';

		/**
		 * hook wp_mail to send transactional emails
		 */

		// check if wp_mail function is already declared by others
		if( function_exists('wp_mail') ) {
			self::$wp_mail_conflict = true;
		}
		$home_settings = get_option('sib_home_option', array());


		if($this->nf_smtp_enalbe == 'yes' && $sib_enabled = 'enable' && self::$wp_mail_conflict == false) {
			function wp_mail($to, $subject, $message, $headers = '', $attachments = array())
			{
				$wc_settings = get_option('wc_sendinblue_settings', array());
				if(strpos( $message, 'WC_SIB') != false && (isset($wc_settings['ws_smtp_enable']) && $wc_settings['ws_smtp_enable'] == 'yes') || strpos( $message, 'NF_SIB') != false) {
					$message = str_replace( 'WC_SIB', '', $message );
					$message = str_replace( 'NF_SIB', '', $message );
					try {
						$sent = NF_Sib_Main::sib_email($to, $subject, $message, $headers, $attachments);
						if (is_wp_error($sent) || !isset($sent['code']) || $sent['code'] != 'success') {
							return NF_Sib_Main::wp_mail_native($to, $subject, $message, $headers, $attachments);
						}
						return true;
					} catch (Exception $e) {
						return NF_Sib_Main::wp_mail_native($to, $subject, $message, $headers, $attachments);
					}
				}
				else{
					return NF_Sib_Main::wp_mail_native($to, $subject, $message, $headers, $attachments);
				}
			}
		}
		elseif($this->nf_smtp_enalbe == 'yes' && $sib_enabled = 'enable' && self::$wp_mail_conflict == true){
			if( !isset($home_settings['activate_email']) || $home_settings['activate_email'] != 'yes')
			{
				add_action('admin_notices', array(&$this, 'wpMailNotices'));
				return;
			}
		}
	}

	/**
	 * Plugin text domain
	 *
	 * @since       1.0
	 * @return      void
	 */
	public function ninja_forms_sb_textdomain()
	{

		// Set filter for plugin's languages directory
		$edd_lang_dir = dirname(plugin_basename(__FILE__)) . '/languages/';
		$edd_lang_dir = apply_filters('ninja_forms_sb_languages_directory', $edd_lang_dir);
		// Load the translations
		load_plugin_textdomain('ninja_forms_sib', false, $edd_lang_dir);
	}

	/**
	 * Add setting group for sendinblue in settings page
	 *
	 * @param $groups
	 * @return mixed
	 */
	public function ninja_forms_sib_settings_group($groups)
	{
		$groups['sendinblue'] = array(
			'id' => 'sendinblue',
			'label' => __('Sendinblue', 'ninja_forms_sib'),
		);
		return $groups;
	}

	/**
	 * Add api key to the sendinblue setting section
	 *
	 * @param $settings
	 * @return mixed
	 */
	public function ninja_forms_sib_plugin_settings($settings)
	{
		$nf_options = get_option( "ninja_forms_settings" );
		$nf_sib_enable = isset($nf_options['nf_sib_smtp_enable']) ? $nf_options['nf_sib_smtp_enable'] : 'no';
		if($nf_sib_enable == 'yes')
		{
			$checked_yes = "checked='checked'";
			$checked_no = " ";
		}
		else
		{
			$checked_no = "checked='checked'";
			$checked_yes = " ";
		}
		//$checked = "checked='checked'";
		$settings['sendinblue'] = array(
			'ninja_forms_sib_api' => array(
				'id' => 'ninja_forms_sib_api',
				'type' => 'textbox',
				'label' => __('SendinBlue API Key', 'ninja_forms_sb'),
				'desc' => __('Enter your SendinBlue API key', 'ninja_forms_sib'),
			),
			'ninja_enable_sib' => array(
				'id'    => 'ninja_forms_sib_enable',
				'type'  => 'html',
				'label' => __('Activate Email through SendinBlue', 'ninja_forms_sib'),
				'html'  => "<input type='radio' name='ninja_forms[nf_sib_smtp_enable]' value='yes' ".$checked_yes."><label style='margin-right: 15px;'>Yes</label><input type='radio' name='ninja_forms[nf_sib_smtp_enable]' value='no' ".$checked_no."><lavel>No</lavel>",
				'desc'  => __('Choose "Yes" if you want to use SendinBlue SMTP to send transactional emails', 'ninja_forms_sib')
			)
		);
		return $settings;
	}

	/**
	 * Register sendinblue add on Action
	 *
	 * @param $actions
	 * @return mixed
	 */
	public function Load_ninja_sendinblue_actions($actions)
	{
		if ( !class_exists( 'NF_Sendinblue_Action' ) )
			require_once('sendinblue/nf-sib-action.php');

		$option = get_option( "ninja_forms_settings" );
		if(isset($option['ninja_forms_sib_api'])) {
			$mailin_ninja = new Mailin_ninja("https://api.sendinblue.com/v2.0", $option['ninja_forms_sib_api']);
			$response = $mailin_ninja->get_access_tokens();
			if ($response['code'] == 'success') {
				$actions[strtolower('Sendinblue')] = new NF_Sendinblue_Action();
				update_option('nf_sib_enabled', 'enable');
			}
		}
		return $actions;
	}

	/**
	 * Plugin Updater / licensing
	 *
	 * @since       1.0.2
	 * @return      void
	 */

	public function ninja_forms_sb_extension_setup_license()
	{
		if (class_exists('NF_Extension_Updater')) {
			$NF_Extension_Updater = new NF_Extension_Updater('', '3.0.0', ' ', __FILE__, '');
		}
	}
	/**
	 * Notice wp_mail is not possible
	 */
	static function wpMailNotices() {
		if ( self::$wp_mail_conflict ) {
			echo '<div class="error"><p>'.__('You cannot to use SendinBlue SMTP now because wp_mail has been declared by another process or plugin. ', 'ninja_forms_sib') . '</p></div>';
		}
	}
	/**
	 * use SendinBlue SMTP to send all emails
	 *
	 * @return boolean
	 */
	static function wp_mail_native( $to, $subject, $message, $headers = '', $attachments = array() ) {
		require self::$plugin_dir . '/sendinblue/function.wp_mail.php';
	}
	/**
	 * to send the transactional email via sendinblue
	 * hook wp_mail
	 */
	static function sib_email($to, $subject, $message, $headers = '', $attachments = array(),$tags = array(),$from_name = '',$from_email = ''){

		// Compact the input, apply the filters, and extract them back out
		extract( apply_filters( 'wp_mail', compact( 'to', 'subject', 'message', 'headers', 'attachments' ) ) );

		if ( !is_array($attachments) )
			$attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );

		// From email and name
		if ( $from_email == '' ) {
			$from_email = trim(get_bloginfo('admin_email'));
			$from_name = trim(get_bloginfo('name'));
		}
		//
		$from_email  = apply_filters('wp_mail_from', $from_email);
		$from_name = apply_filters('wp_mail_from_name', $from_name);

		// Headers
		if ( empty( $headers ) ) {
			$headers = $reply = $bcc = $cc = array();
		} else {
			if ( !is_array( $headers ) ) {
				// Explode the headers out, so this function can take both
				// string headers and an array of headers.
				$tempheaders = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
			} else {
				$tempheaders = $headers;
			}
			$headers = $reply = $bcc = $cc = array();

			// If it's actually got contents
			if ( !empty( $tempheaders ) ) {
				// Iterate through the raw headers
				foreach ( (array) $tempheaders as $header ) {
					if ( strpos($header, ':') === false ) {
						if ( false !== stripos( $header, 'boundary=' ) ) {
							$parts = preg_split('/boundary=/i', trim( $header ) );
							$boundary = trim( str_replace( array( "'", '"' ), '', $parts[1] ) );
						}
						continue;
					}
					// Explode them out
					list( $name, $content ) = explode( ':', trim( $header ), 2 );

					// Cleanup crew
					$name    = trim( $name );
					$content = trim( $content );

					switch ( strtolower( $name ) ) {
						case 'content-type':
							$headers[trim( $name )] =  trim( $content );
							break;
						case 'x-mailin-tag':
							$headers[trim( $name )] =  trim( $content );
							break;
						case 'from':
							if ( strpos($content, '<' ) !== false ) {
								// So... making my life hard again?
								$from_name = substr( $content, 0, strpos( $content, '<' ) - 1 );
								$from_name = str_replace( '"', '', $from_name );
								$from_name = trim( $from_name );

								$from_email = substr( $content, strpos( $content, '<' ) + 1 );
								$from_email = str_replace( '>', '', $from_email );
								$from_email = trim( $from_email );
							} else {
								$from_name  = '';
								$from_email = trim( $content );
							}
							break;

						case 'bcc':
							$bcc[trim( $content )] = '';
							break;
						case 'cc':
							$cc[trim( $content )] = '';
							break;
						case 'reply-to':
							if ( strpos($content, '<' ) !== false ) {
								// So... making my life hard again?
								$reply_to = substr( $content, strpos( $content, '<' ) + 1 );
								$reply_to = str_replace( '>', '', $reply_to );
								$reply[] = trim( $reply_to );
							} else {
								$reply[] = trim( $content );
							}
							break;
						default:
							break;
					}
				}
			}
		}

		// Set destination addresses
		if( !is_array($to) ) $to = explode(',', preg_replace('/\s+/', '', $to)); // strip all whitespace

		$processed_to = array();
		foreach ( $to as $email ) {
			if ( is_array($email) ) {
				$processed_to[] = $email;
			} else {
				$processed_to[$email] = '';
			}
		}
		$to = $processed_to;

		// attachments
		$attachment_content = array();
		if ( !empty( $attachments ) ) {
			foreach ($attachments as $attachment) {
				$content = self::getAttachmentStruct($attachment);
				if (!is_wp_error($content))
					$attachment_content = array_merge($attachment_content, $content);
			}
		}

		// Common transformations for the HTML part
		// if it is text/plain, New line break found;
		if(strpos($message, "</table>") !== FALSE || strpos($message, "</div>") !== FALSE) {
			// html type
		}else {
			if (strpos($message, "\n") !== FALSE) {
				if (is_array($message)) {
					foreach ($message as &$value) {
						$value['content'] = preg_replace('#<(https?://[^*]+)>#', '$1', $value['content']);
						$value['content'] = nl2br($value['content']);
					}
				} else {
					$message = preg_replace('#<(https?://[^*]+)>#', '$1', $message);
					$message = nl2br($message);
				}
			}
		}

		// sending...
		$option = get_option( "ninja_forms_settings" );
		$mailin_ninja = new Mailin_ninja("https://api.sendinblue.com/v2.0",$option['ninja_forms_sib_api']);
		$data = array(
			"to" => $to,
			"from" => array($from_email, $from_name),
			"cc" => $cc,
			"bcc" => $bcc,
			"replyto" => $reply,
			"subject" => $subject,
			"headers" => $headers,
			"attachment" => $attachment_content,
			"html" => $message,
		);

		try{
			$sent = $mailin_ninja->send_email($data);
			return $sent;
		}catch ( Exception $e) {
			return new WP_Error( $e->getMessage() );
		}
	}
	public function ninja_forms_add_sib_tags($message, $data, $action_settings)
	{
		$sib_enabled = get_option('nf_sib_enabled', '');
		$tag_text = ($this->nf_smtp_enalbe == 'yes' && $sib_enabled = 'enable') ? 'NF_SIB' : '';
		$message .= $tag_text;
		return $message;
	}
}

$nf_sib_addOn = new NF_Sib_Main();

