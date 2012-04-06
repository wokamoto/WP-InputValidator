<?php
if ( !class_exists('InputValidater') ) :


/***
$iv = new InputValidater('POST');

$iv->set_rules('email',     array('trim','esc_html','required','email'));
$iv->set_rules('telephone', array('trim','esc_html','required','tel'));
$iv->set_rules('password',  array('trim','esc_html','required'));
$iv->set_rules('password',  'password_min_length', 8);

$input  = $iv->input(array('email', 'telephone', 'password'), true);
$errors = $iv->get_errors();

 ***/

class InputValidater {
	const PASSWORD_MIN_LENGTH = 6;

	private $input  = array();
	private $rules  = array();
	private $errors = array();

	private $rc;

	function __construct( $method = 'POST' ) {
		$method = is_string($method) ? strtoupper($method) : $method;
		switch ($method) {
		case 'POST':
			$this->input = $_POST;
			break;
		case 'GET':
			$this->input = $_GET;
			break;
		case 'COOKIE':
			$this->input = $_COOKIE;
			break;
		default:
			if ( is_array($method) ) {
				$this->input = $method;
			} else {
				$this->input = $_POST;
			}
		}

		$this->rc = new ReflectionClass("InputValidater");
	}

	/*
	 * validate
	 */
	public function validate( $field, $val ) {
		if ( isset($this->rules[$field]) ) {
			foreach ( $this->rules[$field] as $rule ) {
				$func =
					isset($rule['func']) && is_callable($rule['func'])
					? $rule['func']
					: false;
				$args =
					isset($rule['args']) && is_array($rule['args'])
					? $rule['args']
					: array($field);
				$args = array_merge( array($val), $args );

				if ( $func !== false ) {
					if ( false === ($val = call_user_func_array($func, $args) ) ) {
						return false;
					}
				} else {
					return $val;
				}
			}
		}
		return $val;
	}

	private function array_fetch( $array, $index = '', $validate = true ) {
		$val = isset($array[$index]) ? $array[$index] : null;
		return
			$validate
			? $this->validate( $index, $val )
			: $val;
	}

	/*
	 * get input data
	 */
	public function input( $index = false, $validate = false ) {
		if ( !$index ) {
			$post = array();
			foreach (array_keys($this->input) as $key) {
				$post[$key] = $this->array_fetch($this->input, $key, $validate);
			}
			return $post;
		} else if ( is_array($index) ) {
			$post = array();
			foreach ($index as $key) {
				$post[$key] = $this->array_fetch($this->input, $key, $validate);
			}
			return $post;
		} else {
			return $this->array_fetch($this->input, $index, $validate);
		}
	}

	/*
	 * set validate rules
	 */
	public function set_rules( $field, $func ) {
		if ( !isset($this->rules[$field]) ) {
			$this->rules[$field] = array();
		}

		$arg_list = func_get_args();
		unset($arg_list[1]);

		if ( is_string($func) && $this->rc->hasMethod($func) && is_callable(array($this, $func)) ) {
			$this->rules[$field][] = array( 'func' => array(&$this, $func), 'args' => $arg_list );
		} else if ( is_callable($func) ) {
			$this->rules[$field][] = array( 'func' => $func, 'args' => $arg_list );
		} else if ( is_array($func) ) {
			foreach ( $func as $f ) {
				$this->set_rules( $field, $f );
			}
		}
	}

	/*
	 * get errors
	 */
	public function get_errors() {
		return $this->errors;
	}

	public function errors_init() {
		$this->errors = array();
	}

	public function set_errors( $field, $message = '' ) {
		if ( empty($message) )
			$message = sprintf('The "%s" field is invalid.', $field);
		$this->errors[] = array(
			'field' => $field ,
			'message' => $message ,
			);
	}

	/*
	 * validate rules
	 */
	private function trim( $val ) {
		return trim($val);
	}

	private function esc_html( $val ) {
		if ( function_exists('esc_html') ) {
			return esc_html( $val );
		} else {
			$safe_text = $this->wp_check_invalid_utf8( $val );
			$safe_text = $this->_wp_specialchars( $safe_text, ENT_QUOTES );
			return esc_html($safe_text);
		}
	}

	private function required( $val, $field = '' ) {
		if ( empty($val) ) {
			$this->set_errors( $field , sprintf('The "%s" field is required.', $field) );
			return false;
		}
		return $val;
	}

	private function min_length( $val, $field = '', $min_length = false ) {
		if ( !is_numeric($min_length) )
			return $val;
		if ( strlen($val) < $min_length ) {
			$this->set_errors( $field , sprintf('The "%s" field must be at least %s characters in length.', $field, $min_length) );
			return false;
		}
		return $val;
	}

	private function max_length( $val, $field = '', $max_length = false ) {
		if ( !is_numeric($max_length) )
			return $val;
		if ( strlen($val) > $min_length ) {
			$this->set_errors( $field , sprintf('The "%s" field must be at most %s characters in length.', $field, $max_length) );
			return false;
		}
		return $val;
	}

	private function password_min_length( $val, $field = '', $min_length = false ) {
		if ( !is_numeric($min_length) )
			$min_length = self::PASSWORD_MIN_LENGTH;
		return $this->min_length( $val, $field, $min_length );
	}

	private function bool( $val ) {
		if ( is_bool($val) ) {
			return $val;
		} else if ( is_numeric($val) ) {
			return intval($val) > 0;
		} else if (empty($val) || !isset($val) || preg_match('/^(false|off)$/i', $val)) {
			return false;
		} else {
			return true;
		}
	}

	private function email( $val, $field = '' ) {
		$val = str_replace(
			array('＠','。','．','＋'),
			array('@','.','.','+'),
			mb_convert_kana($val, 'as')
			);
		if ( !$this->is_email($val) ) {
			$this->set_errors( $field , sprintf('The "%s" field is invalid.', $field) );
			return false;
		}
		return $val;
	}

	private function tel( $val, $field = '' ) {
		$val = str_replace(
			array('ー','－','（','）'),
			array('-','-','(',')'),
			mb_convert_kana($val, 'ns')
			);
		if ( !preg_match('/^[0-9\-\(\)]+$/', $val) ) {
			$this->set_errors( $field , sprintf('The "%s" field is invalid.', $field) );
			return false;
		}
		return $val;
	}

	private function postcode( $val, $field = '' ) {
		$val = str_replace(
			array('ー','－'),
			array('-','-'),
			mb_convert_kana($val, 'ns')
			);
		if ( !preg_match('/^[0-9\-]+$/', $val) ) {
			$this->set_errors( $field , sprintf('The "%s" field is invalid.', $field) );
			return false;
		}
		return $val;
	}

	private function kana( $val ) {
		$val = mb_convert_kana($val, 'ASKVC');
		return $val;
	}

	/*
	 * utility
	 */
	private function is_email( $email, $deprecated = false ) {
		if ( function_exists('is_email') ) {
			return is_email( $email, $deprecated );
		} else {
			// Test for the minimum length the email can be
			if ( strlen( $email ) < 3 )
				return false;

			// Test for an @ character after the first position
			if ( strpos( $email, '@', 1 ) === false )
				return false;

			// Split out the local and domain parts
			list( $local, $domain ) = explode( '@', $email, 2 );

			// LOCAL PART
			// Test for invalid characters
			if ( !preg_match( '/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~\.-]+$/', $local ) )
				return false;

			// DOMAIN PART
			// Test for sequences of periods
			if ( preg_match( '/\.{2,}/', $domain ) )
				return false;

			// Test for leading and trailing periods and whitespace
			if ( trim( $domain, " \t\n\r\0\x0B." ) !== $domain )
				return false;

			// Split the domain into subs
			$subs = explode( '.', $domain );

			// Assume the domain will have at least two subs
			if ( 2 > count( $subs ) )
				return false;

			// Loop through each sub
			foreach ( $subs as $sub ) {
				// Test for leading and trailing hyphens and whitespace
				if ( trim( $sub, " \t\n\r\0\x0B-" ) !== $sub )
					return false;

				// Test for invalid characters
				if ( !preg_match('/^[a-z0-9-]+$/i', $sub ) )
					return false;
			}

			// Congratulations your email made it!
			return $email;
		}
	}

	private function wp_check_invalid_utf8( $string, $strip = false ) {
		if ( function_exists('wp_check_invalid_utf8') ) {
			return wp_check_invalid_utf8( $string, $strip );
		} else {
			$string = (string) $string;
			if ( 0 === strlen( $string ) ) {
				return '';
			}

			// Check for support for utf8 in the installed PCRE library once and store the result in a static
			static $utf8_pcre;
			if ( !isset( $utf8_pcre ) ) {
				$utf8_pcre = @preg_match( '/^./u', 'a' );
			}
			// We can't demand utf8 in the PCRE installation, so just return the string in those cases
			if ( !$utf8_pcre ) {
				return $string;
			}

			// preg_match fails when it encounters invalid UTF8 in $string
			if ( 1 === @preg_match( '/^./us', $string ) ) {
				return $string;
			}

			// Attempt to strip the bad chars if requested (not recommended)
			if ( $strip && function_exists( 'iconv' ) ) {
				return iconv( 'utf-8', 'utf-8', $string );
			}

			return '';
		}
	}

	private function _wp_specialchars( $string, $quote_style = ENT_NOQUOTES, $charset = false, $double_encode = false ) {
		if ( function_exists('_wp_specialchars') ) {
			return _wp_specialchars( $string, $quote_style, $charset, $double_encode );
		} else {
			$string = (string) $string;
			if ( 0 === strlen( $string ) )
				return '';

			// Don't bother if there are no specialchars - saves some processing
			if ( ! preg_match( '/[&<>"\']/', $string ) )
				return $string;

			// Account for the previous behaviour of the function when the $quote_style is not an accepted value
			if ( empty( $quote_style ) )
				$quote_style = ENT_NOQUOTES;
			elseif ( ! in_array( $quote_style, array( 0, 2, 3, 'single', 'double' ), true ) )
				$quote_style = ENT_QUOTES;

			// Store the site charset as a static to avoid multiple calls to wp_load_alloptions()
			if ( ! $charset )
				$charset = 'UTF-8';

			if ( in_array( $charset, array( 'utf8', 'utf-8', 'UTF8' ) ) )
				$charset = 'UTF-8';

			$_quote_style = $quote_style;
			if ( $quote_style === 'double' ) {
				$quote_style = ENT_COMPAT;
				$_quote_style = ENT_COMPAT;
			} elseif ( $quote_style === 'single' ) {
				$quote_style = ENT_NOQUOTES;
			}

			$string = @htmlspecialchars( $string, $quote_style, $charset );

			// Backwards compatibility
			if ( 'single' === $_quote_style )
				$string = str_replace( "'", '&#039;', $string );

			return $string;
		}
	}

} // end of class


endif;
// EOF