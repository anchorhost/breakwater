<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * What a visitor sent, normalised: who (ip, agent, email, name), what (content) and the
 * browser proof the script attached. Adapters build one with fromFields() when the form
 * plugin knows its field types, or fromRequest() when only the raw POST is available.
 */
final class Submission {

    /** Field keys that belong to form plumbing, never to the visitor's message. */
    public const SKIP = '/^(bw_|_wp|_wpcf7|wpcf7|gform_|is_submit_|state_|gf_|version_hash|action$|post_id$|form_id$|queried_id$|referer_title$|referrer$|nonce|security$|g-recaptcha-response|cf-turnstile-response|h-captcha-response|wpforms\[(id|author|post_id|nonce|token)|_ajax_nonce|redirect_to$|testcookie$|submit$|elementor|woocommerce-|_wp_http_referer|_mc4wp|_fluentform|__fluent|fluentform_|_frm|frm_|item_key$|form_key$|forminator_|_forminator|nf_|_nf|et_pb_contactform_submit_|et_pb_contact_email_fields_|et_pb_contact_email_hidden_fields_|et_pb_contact_captcha_|et_pb_contact_et_number_|et_pb_contact_form_unique_id|et_builder_submit_button|token$|et_post_id$|_wpnonce-et-pb-|fusion_|form_instance_id|_kb_|kb_form|_kad|give-|_give|um_request|form_type$|mode$|_um_)/i';

    /** Values never stored or shown: credentials and card data. */
    public const SECRET = '/pass|pwd|card|cvv|cvc|ccnum|cc_|iban|routing|ssn/i';

    /**
     * Field types whose values are the site's own text, choices or tracking, never the
     * visitor's words: consent labels, hidden fields, choice lists, captchas, payments.
     * Their values are kept for recovery but not judged.
     */
    public const NOT_MESSAGE = [
        'consent', 'gdpr', 'gdpr-checkbox', 'gdprcheckbox', 'gdpr_agreement', 'acceptance', 'accept', 'terms', 'terms_and_condition', 'confirm',
        'hidden', 'html', 'custom_html', 'section', 'section_break', 'page', 'pagebreak', 'page-break', 'break', 'divider', 'end_divider', 'hr', 'note', 'shortcode', 'action_hook',
        'captcha', 'recaptcha', 'recaptcha_v3', 'hcaptcha', 'turnstile', 'honeypot', 'spam', 'quiz',
        'password', 'passwordconfirm', 'creditcard', 'credit-card', 'credit_card', 'stripe', 'stripe-ocs', 'paypal', 'payment', 'product', 'quantity', 'shipping', 'total', 'calculation', 'currency',
        'fileupload', 'file', 'upload', 'image', 'signature', 'post_image',
        'checkbox', 'radio', 'select', 'multiselect', 'multi_select', 'select_country', 'listcountry', 'liststate', 'rating', 'ratings', 'starrating', 'star', 'scale', 'slider', 'rangeslider', 'range', 'toggle', 'likert', 'nps', 'net_promoter_score',
        'date', 'time', 'number', 'submit', 'button', 'user_id',
    ];

    /** Plugin type names for the same thing, mapped to one vocabulary. */
    private const TYPE_ALIASES = [
        'user_email' => 'email', 'email_address' => 'email',
        'firstname' => 'first_name', 'fname' => 'first_name',
        'lastname' => 'last_name', 'lname' => 'last_name',
        'fullname' => 'name', 'full_name' => 'name', 'user_login' => 'name', 'username' => 'name',
        'website' => 'url', 'user_url' => 'url',
        'rich_text_input' => 'textarea', 'message' => 'textarea',
    ];

    public string $source  = '';
    public string $form    = '';
    public string $ip      = '';
    public string $agent   = '';
    public string $email   = '';
    public string $name    = '';
    public string $url     = '';
    public string $content = '';
    /** The visitor's own message field (a textarea), when the form says which one it is. */
    public string $message = '';
    /** @var array<string, string> what is stored for recovery, secrets redacted */
    public array $fields   = [];
    public string $token   = '';
    /** @var array<string, mixed>|null timing and interaction counts from the script, null when it never ran */
    public ?array $meta    = null;
    /** @var array<string, string> what the browser says about itself beyond the user agent (fetch metadata, client hints), from the submitting request */
    public array $headers  = [];
    public string $trap    = '';
    /** The visitor came back to a form they saved earlier (the form plugin vouches for it). */
    public bool $continued = false;
    /** The other spam service's verdict for comparison: null when nothing else judged it. */
    public ?bool $other    = null;

    /**
     * From the raw POST: every visitor field is message text, the first email-looking value
     * is the sender, and a key containing "name" is their name.
     *
     * @param array<string, mixed>|null $post Defaults to the current request.
     */
    public static function fromRequest( string $source, string $form = '', ?array $post = null ): self {
        $post = $post ?? self::post();
        $s    = self::browser( $source, $form, $post );

        $texts = [];
        foreach ( self::flatten( $post ) as $key => $value ) {
            if ( '' === $value || preg_match( self::SKIP, $key ) ) {
                continue;
            }
            if ( preg_match( self::SECRET, $key ) ) {
                $s->fields[ $key ] = '[redacted]';
                continue;
            }
            $s->fields[ $key ] = $value;
            if ( '' === $s->email && is_email( $value ) ) {
                $s->email = $value;
                continue;
            }
            if ( '' === $s->name && preg_match( '/name/i', $key ) && strlen( $value ) < 100 ) {
                $s->name = $value;
            }
            $texts[] = $value;
        }
        $s->content = implode( "\n", $texts );
        return $s;
    }

    /**
     * From fields the form plugin has already typed. Each field is
     * ['type' => ..., 'label' => ..., 'value' => string|array, 'key' => optional].
     * Email, name and website fields fill the sender; consent, hidden and similar fields are
     * kept for recovery but never judged as the visitor's words.
     *
     * @param list<array{type?:string, label?:string, value?:mixed, key?:string}> $fields
     */
    public static function fromFields( string $source, string $form, array $fields ): self {
        $s     = self::browser( $source, $form, self::post() );
        $texts = [];
        $first = '';
        $last  = '';
        foreach ( $fields as $i => $field ) {
            $type  = self::canonical( (string) ( $field['type'] ?? '' ) );
            $label = trim( wp_strip_all_tags( (string) ( $field['label'] ?? '' ) ) );
            $value = self::text( $field['value'] ?? '' );
            if ( '' === $value ) {
                continue;
            }
            $key = mb_substr( '' !== $label ? $label : (string) ( $field['key'] ?? $type ?: $i ), 0, 60 );
            if ( isset( $s->fields[ $key ] ) ) {
                $key .= ' [' . ( $field['key'] ?? $i ) . ']';
            }
            if ( in_array( $type, [ 'password', 'passwordconfirm', 'creditcard', 'credit-card', 'credit_card' ], true ) || preg_match( self::SECRET, $key ) ) {
                $s->fields[ $key ] = '[redacted]';
                continue;
            }
            $s->fields[ $key ] = $value;
            if ( in_array( $type, self::NOT_MESSAGE, true ) ) {
                continue;
            }
            if ( 'email' === $type || ( '' === $s->email && 'text' === $type && is_email( $value ) ) ) {
                $s->email = '' === $s->email ? $value : $s->email;
                continue;
            }
            if ( 'textarea' === $type && strlen( $value ) > strlen( $s->message ) ) {
                $s->message = $value;
            }
            match ( $type ) {
                'first_name' => $first = $value,
                'last_name'  => $last = $value,
                'name'       => $s->name = '' === $s->name ? $value : $s->name,
                'url'        => $s->url = '' === $s->url ? $value : $s->url,
                default      => null,
            };
            $texts[] = $value;
        }
        if ( '' === $s->name ) {
            $s->name = trim( $first . ' ' . $last );
        }
        $s->content = implode( "\n", $texts );
        return $s;
    }

    /** "input_email" (Fluent Forms), "Email" and "user_email" all become "email". */
    public static function canonical( string $type ): string {
        $type = strtolower( trim( $type ) );
        $type = (string) preg_replace( '/^input_/', '', $type );
        return self::TYPE_ALIASES[ $type ] ?? $type;
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function browser( string $source, string $form, array $post ): self {
        $s         = new self();
        $s->source = $source;
        $s->form   = $form;
        $s->ip     = self::ip();
        $s->agent  = substr( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 300 );
        $s->headers = self::headers();
        $s->trap   = is_string( $post['bw_url'] ?? null ) ? trim( $post['bw_url'] ) : '';
        // The form's own hidden fields first; the cookies cover plugins that post their own payload.
        $s->token = self::proof( $post, 'bw_token' );
        $meta     = json_decode( self::proof( $post, 'bw_meta' ), true );
        $s->meta  = is_array( $meta ) ? $meta : null;
        return $s;
    }

    /**
     * Headers every current browser sends with a form and most HTTP libraries don't: fetch
     * metadata (Sec-Fetch-*, secure pages only) and Chromium's client hints (Sec-CH-UA*).
     * Read for the consistency signals and kept with the log row.
     *
     * @return array<string, string>
     */
    private static function headers(): array {
        $out = [];
        foreach ( [
            'fetch_site' => 'HTTP_SEC_FETCH_SITE', 'fetch_mode' => 'HTTP_SEC_FETCH_MODE', 'fetch_dest' => 'HTTP_SEC_FETCH_DEST',
            'ch_ua' => 'HTTP_SEC_CH_UA', 'ch_mobile' => 'HTTP_SEC_CH_UA_MOBILE', 'ch_platform' => 'HTTP_SEC_CH_UA_PLATFORM',
            'language' => 'HTTP_ACCEPT_LANGUAGE', 'accept' => 'HTTP_ACCEPT',
        ] as $key => $server ) {
            if ( is_string( $_SERVER[ $server ] ?? null ) && '' !== $_SERVER[ $server ] ) {
                $out[ $key ] = substr( (string) wp_unslash( $_SERVER[ $server ] ), 0, 200 );
            }
        }
        if ( $out || isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
            $out['https'] = is_ssl() ? '1' : '0';
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function proof( array $post, string $name ): string {
        if ( is_string( $post[ $name ] ?? null ) && '' !== $post[ $name ] ) {
            return $post[ $name ];
        }
        return is_string( $_COOKIE[ $name ] ?? null ) ? (string) wp_unslash( $_COOKIE[ $name ] ) : '';
    }

    /** @return array<string, mixed> */
    private static function post(): array {
        return wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
    }

    private static function text( mixed $value ): string {
        if ( is_array( $value ) ) {
            return trim( implode( ' ', array_filter( array_map( [ self::class, 'text' ], $value ), 'strlen' ) ) );
        }
        return is_scalar( $value ) ? trim( (string) $value ) : '';
    }

    public static function ip(): string {
        $ip = (string) apply_filters( 'breakwater_ip', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
    }

    /**
     * ['a' => ['b' => 'x']] becomes ['a[b]' => 'x']. Only scalars survive.
     *
     * @return array<string, string>
     */
    public static function flatten( mixed $data, string $prefix = '' ): array {
        $out = [];
        foreach ( (array) $data as $key => $value ) {
            $name = '' === $prefix ? (string) $key : $prefix . '[' . $key . ']';
            if ( is_array( $value ) ) {
                $out += self::flatten( $value, $name );
            } elseif ( is_scalar( $value ) ) {
                $out[ $name ] = trim( (string) $value );
            }
        }
        return $out;
    }

    public function hash(): string {
        $text = strtolower( (string) preg_replace( '/\s+/', ' ', $this->content ) );
        return '' === trim( $text ) ? '' : sha1( $text );
    }
}
