<?php

/*
  Plugin Name: twak's papercite
  Plugin URI: https://github.com/twak/leeds-wp-papercite
  Description: fixes / patches for leeds vcg website
  Version: 0.0.⅓
  Author: Benjamin Piwowarski
  Author URI: http://www.bpiwowar.net
  Author: digfish1
  Author URI: http://digfish.org
*/

// isolate papercite class in their own class file, keeping only the wordpress integtation
// in this file
require_once "papercite.classes.php";



// -------------------- Interface with WordPress


// --- Head of the HTML ----
function papercite_head()
{
    if (!function_exists('wp_enqueue_script')) {
      // In case there is no wp_enqueue_script function (WP < 2.6), we load the javascript ourselves
        echo "\n" . '<script src="'.  get_bloginfo('wpurl') . '/wp-content/plugins/leeds-wp-papercite/js/jquery.js"  type="text/javascript"></script>' . "\n";
        echo '<script src="'.  get_bloginfo('wpurl') . '/wp-content/leeds-wp-papercite/papercite/js/papercite.js"  type="text/javascript"></script>' . "\n";
    }
}

// --- Initialise papercite ---
function papercite_init()
{
    global $papercite;

    if (function_exists('wp_enqueue_script')) {
        wp_register_script('papercite', plugins_url('leeds-wp-papercite/js/papercite.js'), array('jquery'));
        wp_enqueue_script('papercite');
    }

  // Register and enqueue the stylesheet
    wp_register_style('papercite_css', plugins_url('leeds-wp-papercite/papercite.css'));
    wp_enqueue_style('papercite_css');

  // Initialise the object
    $papercite = new Papercite();
}

// --- Callback function ----
/**
 * @param $myContent
 *
 * @return mixed|string|string[]|null
 */
function &papercite_cb($myContent)
{
  // Init
    $papercite = &$GLOBALS["papercite"];
  
  // Fixes issue #39 (maintenance mode support)
    if (!is_object($papercite)) {
        return $myContent;
    }

    $papercite->init();

  // Database support if needed
    if ($papercite->options["use_db"]) {
        require_once(dirname(__FILE__) . "/papercite_db.php");
    }
    
  // (0) Skip processing on this page?
    if ($papercite->options['skip_for_post_lists'] && !is_single() && !is_page()) {
//        return preg_replace("/\[\s*((?:\/)bibshow|bibshow|bibcite|bibtex|ppcnote)(?:\s+([^[]+))?]/", '', $myContent);
        return preg_replace("/\[\s*((?:\/)bibshow|bibshow|bibcite|bibtex)(?:\s+([^[]+))?]/", '', $myContent);
    }

  // (1) First phase - handles everything but bibcite keys
    $text = preg_replace_callback(
 //       "/\[\s*((?:\/)bibshow|bibshow|bibcite|bibtex|bibfilter|ppcnote)(?:\s+([^[]+))?]/",
        "/\[\s*((?:\/)bibshow|bibshow|bibcite|bibtex|bibfilter)(?:\s+([^[]+))?]/",
        array($papercite, "process"),
        $myContent
    );

    //digfish: textual footnotes
/*    $note_matches = array();
    $note_matches_count = preg_match_all('/\[ppcnote\](.+?)\[\/ppcnote\]/i',$text,$note_matches);

    if ($note_matches_count !== FALSE) {
	    $ft_matches = $note_matches[1];
	    foreach ($ft_matches as $match) {
	        $papercite->textual_footnotes[] = $match;
	    }
    }*/

    $post_id = get_the_ID();

    $text = preg_replace_callback(
            '/\[ppcnote\](.+?)\[\/ppcnote\]/i',
            function($match) use($post_id,$papercite) {
                return $papercite->processTextualFootnotes($match,$post_id);
            },
        $text
    );

    if ( count($papercite->getTextualFootnotes() ) > 0) {
	    $text .= $papercite->showTextualFootnotes(get_the_ID());
    }

    // digfish: reset the footnotes after the end of post/page
    $papercite->textual_footnotes = array();
    $papercite->textual_footnotes_counter = 0;


  // (2) Handles missing bibshow tags
    while (sizeof($papercite->bibshows) > 0) {
        $text .= $papercite->end_bibshow();
    }

//    $loop = new WP_Query(array(
//        'post_type' => 'tk_profiles'
//    ) );
//
//    // and that was the day I decided that I wanted to give up my faculty job, and become a php developer. todo move to inner loop so it only works on name string.
//    if ( $loop->have_posts() )
//        while ( $loop->have_posts() ) {
//            $loop->the_post();
//            $bibtex =  get_field('tk_profiles_bibtex_name');
//            if ($bibtex)
//                $text = str_replace( $bibtex, "<a href='".  esc_url( apply_filters( 'tk_profile_url', '', get_the_id() ) ) ."'>".$bibtex."</a>", $text);
//        }
//    wp_reset_postdata();

    return $text;
}

// --- Add the documentation link in the plugin list
function papercite_row_cb($data, $file)
{
    if ($file == "papercite/papercite-wp-plugin.php") {
        $data[] = "<a href='" . WP_PLUGIN_URL . "/papercite/documentation/index.html'>Documentation</a>";
    }
    return $data;
}
add_filter('plugin_row_meta', 'papercite_row_cb', 1, 2);

// --- Register the MIME type for Bibtex files
function papercite_mime_types($mime_types)
{
  // Adjust the $mime_types, which is an associative array where the key is extension and value is mime type.
    $mime_types['bib'] = 'application/x-bibtex'; // Adding bibtex
    return $mime_types;
}
add_filter('upload_mimes', 'papercite_mime_types', 1, 1);

/**
 * by digfish (09 Apr 2019)
 * Restore .bib upload functionality in Media Library for WordPress 4.9.9 and up
 * adapted from https://gist.github.com/rmpel/e1e2452ca06ab621fe061e0fde7ae150
 */
add_filter('wp_check_filetype_and_ext', function($values, $file, $filename, $mimes) {
    if ( extension_loaded( 'fileinfo' ) ) {
        // with the php-extension, a bib file is issues type text/plain so we fix that back to
        // application/x-bibtex by trusting the file extension.
        $finfo     = finfo_open( FILEINFO_MIME_TYPE );
        $real_mime = finfo_file( $finfo, $file );
        finfo_close( $finfo );
        if ( $real_mime === 'text/plain' && preg_match( '/\.(bib)$/i', $filename ) ) {
            $values['ext']  = 'bib';
            $values['type'] = 'application/x-bibtex';
        }
    } else {
        // without the php- extension, we probably don't have the issue at all, but just to be sure...
        if ( preg_match( '/\.(bib)$/i', $filename ) ) {
            $values['ext']  = 'bib';
            $values['type'] = 'application/x-bibtex';
        }
    }
    return $values;
}, PHP_INT_MAX, 4);


register_activation_hook(__FILE__, 'my_activation');

function my_activation() {
    if (! wp_next_scheduled ( 'papercite_fetch_whiterose' )) {
		wp_schedule_event(time(), 'hourly', 'papercite_fetch_whiterose');
    }
}

add_action('papercite_fetch_whiterose', 'papercite_fetch_whiterose');
function papercite_fetch_whiterose() { // attempt to re-cache each user's bibtex files from whiterose

    $loop = new WP_Query(array(
        'post_type' => 'tk_profiles'
    ));

    $count = 0;
    if ($loop->have_posts())
        while ($loop->have_posts()) {
            $loop->the_post();
            $url = get_field('tk_profiles_bibtex_url');
            if ($url) {
                error_log( $url );
                // schedule 11 minutes apart, so cron tasks not killed by wp...
                wp_schedule_single_event(time() + 60 * 11 * $count, 'papercite_fetch_whiterose_for_', array($url));
                $count++;
            }
        }
    wp_reset_postdata();
}


add_action('papercite_fetch_whiterose_for_', 'papercite_fetch_whiterose_for', 10, 1);
function papercite_fetch_whiterose_for($url) { // re-cache bibtex file if expired
    papercite_cb("[bibtex file=".$url." sort=year order=desc]");
}

register_deactivation_hook(__FILE__, 'my_deactivation');
function my_deactivation() {
	wp_clear_scheduled_hook('papercite_fetch_whiterose');
}


// --- Add the different handlers to WordPress ---
add_action('init', 'papercite_init');
add_action('wp_head', 'papercite_head');
add_filter('the_content', 'papercite_cb', -1);


?>
