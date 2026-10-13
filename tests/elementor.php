<?php
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-load.php' ) ) { fwrite( STDERR, "Usage: php tests/elementor.php /path/to/local/wordpress\n" ); exit( 1 ); }
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
define( 'WP_ADMIN', true );
$_SERVER['HTTP_HOST']='127.0.0.1:8764';
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['REQUEST_URI']='/services/';
require $argv[1] . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local environment required.' ); }
if ( ! class_exists( '\Elementor\Plugin' ) ) { throw new RuntimeException( 'Activate Elementor in isolated local WordPress first.' ); }
$checks=0;
function elementor_check( bool $pass, string $name ): void { $GLOBALS['checks']++; if ( ! $pass ) { throw new RuntimeException( $name ); } }
CPTAI_Settings::save( array( 'source'=>'en', 'targets'=>array('es','pt','zh') ) );
CPTAI_Elementor::styles();
$manager=\Elementor\Plugin::instance()->widgets_manager;
$widget=$manager->get_widget_types('cptai-language-switcher');
elementor_check( $widget instanceof CPTAI_Elementor_Language_Widget, 'native widget registered in real Elementor' );
$controls=$widget->get_controls();
foreach (array('display','en_flag','pt_flag','language_gap','flag_size','flag_text_gap','typography_font_size','typography_font_family','typography_font_weight','typography_text_transform','typography_font_style','typography_text_decoration','typography_line_height','typography_letter_spacing','text_color_normal','text_color_hover','text_color_active','alignment') as $name) {
    elementor_check( isset($controls[$name]), 'Elementor control: '.$name );
}
elementor_check( count($controls['display']['options'])===5, 'all five display modes available' );
elementor_check( wp_style_is('cptai-switcher','registered') && array('cptai-switcher')===$widget->get_style_depends(), 'widget stylesheet dependency registered' );
elementor_check( true === $widget->get_config()['is_dynamic_content'], 'Elementor output cache disabled for page-specific language URLs' );
foreach (array('flags','flags_code','flags_name','name','code') as $mode) {
    $html=CPTAI_Elementor::render(array('display'=>$mode,'en_flag'=>'gb','pt_flag'=>'br'));
    $doc=new DOMDocument(); @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);
    $xpath=new DOMXPath($doc);
    elementor_check( $xpath->query('//a')->length===4, 'widget takes configured languages: '.$mode );
    elementor_check( $xpath->query('//img')->length===(str_starts_with($mode,'flags')?4:0), 'flag count: '.$mode );
    elementor_check( $xpath->query('//span')->length===('flags'===$mode?0:4), 'text visibility: '.$mode );
    elementor_check( str_contains($html,'/es/services/') && str_contains($html,'/zh/services/'), 'widget links keep current page and language: '.$mode );
    if (str_starts_with($mode,'flags')) { elementor_check( str_contains($html,'/gb.svg') && str_contains($html,'/br.svg'), 'selected UK and Brazil flag assets: '.$mode ); }
}
$html=CPTAI_Elementor::render(array('display'=>'flags','en_flag'=>'us','pt_flag'=>'pt'));
elementor_check( str_contains($html,'/us.svg') && str_contains($html,'/pt.svg'), 'US and Portugal alternatives' );
$html=CPTAI_Elementor::render(array('display'=>'flags','en_flag'=>'gb-eng'));
elementor_check( str_contains($html,'/gb-eng.svg'), 'England flag available separately from UK' );
$document_id=wp_insert_post(array('post_title'=>'CPTAI widget editor fixture','post_name'=>'cptai-widget-editor-fixture','post_type'=>'page','post_status'=>'publish'));
update_post_meta($document_id,'_elementor_edit_mode','builder');
$documents=\Elementor\Plugin::instance()->documents;
$document=$documents->get($document_id);
$documents->switch_to_document($document);
$_SERVER['REQUEST_URI']='/wp-admin/admin-ajax.php';
$html=CPTAI_Elementor::render(array('display'=>'flags_name'));
elementor_check( str_contains($html,'/es/cptai-widget-editor-fixture/') && !str_contains($html,'href="http://127.0.0.1:8764/wp-admin'), 'editor AJAX uses public document permalink' );
$documents->restore_document();
$_SERVER['REQUEST_URI']='/services/';
wp_delete_post($document_id,true);
CPTAI_Settings::save(array('source'=>'en','targets'=>array('es')));
$html=CPTAI_Elementor::render(array('display'=>'flags_name'));
elementor_check( !str_contains($html,'hreflang="pt"') && !str_contains($html,'hreflang="zh"'), 'widget updates when languages are removed' );
foreach (glob(dirname(__DIR__).'/assets/flags/*.svg') as $file) {
    $doc=new DOMDocument();
    elementor_check( $doc->load($file) && $doc->documentElement->tagName==='svg' && !str_contains(file_get_contents($file),'<script'), 'valid packaged SVG flag: '.basename($file) );
}
CPTAI_Settings::save(array('source'=>'en','targets'=>array('es','pt','zh')));
echo "PASS: $checks real Elementor checks.\n";
