<?php
/**
* Posts thumbnails actions
*
*
* @package      Customizr
* @subpackage   classes
* @since        3.0.5
* @author       Nicolas GUILLAUME <nicolas@presscustomizr.com>
* @copyright    Copyright (c) 2013-2015, Nicolas GUILLAUME
* @link         http://presscustomizr.com/customizr
* @license      http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/
if ( ! class_exists( 'CZR_post_thumbnails' ) ) :
class CZR_post_thumbnails {
    static $instance;
    function __construct () {
      self::$instance =& $this;
      //may be filter the thumbnail inline style
      add_filter( 'tc_post_thumb_inline_style'  , array( $this , 'czr_fn_change_thumb_inline_css' ), 10, 3 );
    }



    /**********************
    * THUMBNAIL MODELS
    **********************/
    /**
    * Gets the thumbnail or the first images attached to the post if any
    * inside loop
    * @return array( $tc_thumb(image object), $tc_thumb_width(string), $tc_thumb_height(string) )
    *
    * @package Customizr
    * @since Customizr 1.0
    */
    function czr_fn_get_thumbnail_model( $requested_size = null, $_post_id = null , $_custom_thumb_id = null, $_enable_wp_responsive_imgs = null ) {
      if ( ! $this -> czr_fn_has_thumb( $_post_id, $_custom_thumb_id ) )
        return array();

      $tc_thumb_size              = is_null($requested_size) ? apply_filters( 'tc_thumb_size_name' , 'tc-thumb' ) : $requested_size;
      $_post_id                   = is_null($_post_id) ? get_the_ID() : $_post_id;
      $_filtered_thumb_size       = apply_filters( 'tc_thumb_size' , CZR_init::$instance -> tc_thumb_size );
      $_model                     = array();
      $_img_attr                  = array();
      $tc_thumb_height            = '';
      $tc_thumb_width             = '';

      //when null set it as the image setting for reponsive thumbnails (default)
      //because this method is also called from the slider of posts which refers to the slider responsive image setting
      //limit this just for wp version >= 4.4
      if ( version_compare( $GLOBALS['wp_version'], '4.4', '>=' ) )
        $_enable_wp_responsive_imgs = is_null( $_enable_wp_responsive_imgs ) ? 1 == CZR_utils::$inst->czr_fn_opt('tc_resp_thumbs_img') : $_enable_wp_responsive_imgs;

      //try to extract $_thumb_id and $_thumb_type
      extract( $this -> czr_fn_get_thumb_info( $_post_id, $_custom_thumb_id ) );
      if ( ! apply_filters( 'czr_fn_has_thumb_info', isset($_thumb_id) && false != $_thumb_id && ! is_null($_thumb_id) ) )
        return array();

      //Try to get the image
      $image                      = wp_get_attachment_image_src( $_thumb_id, $tc_thumb_size);
      if ( ! apply_filters('tc_has_wp_thumb_image', ! empty( $image[0] ) ) )
        return array();

      //check also if this array value isset. (=> JetPack photon bug)
      if ( isset($image[3]) && false == $image[3] && 'tc-thumb' == $tc_thumb_size )
        $tc_thumb_size          = 'large';
      if ( isset($image[3]) && false == $image[3] && 'tc_rectangular_size' == $tc_thumb_size )
        $tc_thumb_size          = 'slider';

      $_img_attr['class']     = sprintf( 'attachment-%1$s tc-thumb-type-%2$s wp-post-image' , $tc_thumb_size , $_thumb_type );
      //Add the style value
      $_style                 = apply_filters( 'tc_post_thumb_inline_style' , '', $image, $_filtered_thumb_size );
      if ( $_style )
        $_img_attr['style']   = $_style;
      $_img_attr              = apply_filters( 'tc_post_thumbnail_img_attributes' , $_img_attr );

      //we might not want responsive images
      if ( false === $_enable_wp_responsive_imgs ) {
        //trick, will produce an empty attr srcset as in wp-includes/media.php the srcset is calculated and added
        //only when the passed srcset attr is not empty. This will avoid us to:
        //a) add a filter to get rid of already computed srcset
        // or
        //b) use preg_replace to get rid of srcset and sizes attributes from the generated html
        //Side effect:
        //we'll see an empty ( or " " depending on the browser ) srcset attribute in the html
        //to avoid this we filter the attributes getting rid of the srcset if any.
        //Basically this trick, even if ugly, will avoid the srcset attr computation
        $_img_attr['srcset']  = " ";
        add_filter( 'wp_get_attachment_image_attributes', array( $this, 'czr_fn_remove_srcset_attr' ) );
      }
      //get the thumb html
      if ( is_null($_custom_thumb_id) && has_post_thumbnail( $_post_id ) )
        //get_the_post_thumbnail( $post_id, $size, $attr )
        $tc_thumb = get_the_post_thumbnail( $_post_id , $tc_thumb_size , $_img_attr);
      else
        //wp_get_attachment_image( $attachment_id, $size, $icon, $attr )
        $tc_thumb = wp_get_attachment_image( $_thumb_id, $tc_thumb_size, false, $_img_attr );

      //get height and width if not empty
      if ( ! empty($image[1]) && ! empty($image[2]) ) {
        $tc_thumb_height        = $image[2];
        $tc_thumb_width         = $image[1];
      }
      //used for smart load when enabled
      $tc_thumb = apply_filters( 'tc_thumb_html', $tc_thumb, $requested_size, $_post_id, $_custom_thumb_id, $_img_attr, $tc_thumb_size );

      return apply_filters( 'tc_get_thumbnail_model',
        isset($tc_thumb) && ! empty($tc_thumb) && false != $tc_thumb ? compact( "tc_thumb" , "tc_thumb_height" , "tc_thumb_width" ) : array(),
        $_post_id,
        $_thumb_id,
        $_enable_wp_responsive_imgs
      );
    }



    /**
    * inside loop
    * @return array( "_thumb_id" , "_thumb_type" )
    */
    private function czr_fn_get_thumb_info( $_post_id = null, $_thumb_id = null ) {
      $_post_id     = is_null($_post_id) ? get_the_ID() : $_post_id;
      $_meta_thumb  = get_post_meta( $_post_id , 'tc-thumb-fld', true );
      //get_post_meta( $post_id, $key, $single );
      //always refresh the thumb meta if user logged in and current_user_can('upload_files')
      //When do we refresh ?
      //1) empty( $_meta_thumb )
      //2) is_user_logged_in() && current_user_can('upload_files')
      $_refresh_bool = empty( $_meta_thumb ) || ! $_meta_thumb;
      $_refresh_bool = ! isset($_meta_thumb["_thumb_id"]) || ! isset($_meta_thumb["_thumb_type"]);
      $_refresh_bool = ( is_user_logged_in() && current_user_can('upload_files') ) ? true : $_refresh_bool;
      //if a custom $_thumb_id is requested => always refresh
      $_refresh_bool = ! is_null( $_thumb_id ) ? true : $_refresh_bool;

      if ( ! $_refresh_bool )
        return $_meta_thumb;

      return $this -> czr_fn_set_thumb_info( $_post_id , $_thumb_id, true );
    }

    /**************************
    * EXPOSED HELPERS / SETTERS
    **************************/
    /*
    * @return bool
    */
    public function czr_fn_has_thumb( $_post_id = null , $_thumb_id = null ) {
      $_post_id  = is_null($_post_id) ? get_the_ID() : $_post_id;
      //try to extract (OVERWRITE) $_thumb_id and $_thumb_type
      extract( $this -> czr_fn_get_thumb_info( $_post_id, $_thumb_id ) );
      return apply_filters( 'czr_fn_has_thumb', wp_attachment_is_image($_thumb_id) && isset($_thumb_id) && false != $_thumb_id && ! empty($_thumb_id) );
    }


    /**
    * update the thumb meta and maybe return the info
    * public because also fired from admin on save_post
    * @param post_id and (bool) return
    * @return void or array( "_thumb_id" , "_thumb_type" )
    */
    public function czr_fn_set_thumb_info( $post_id = null , $_thumb_id = null, $_return = false ) {
      $post_id      = is_null($post_id) ? get_the_ID() : $post_id;
      $_thumb_type  = 'none';

      //IF a custom thumb id is requested
      if ( ! is_null( $_thumb_id ) && false !== $_thumb_id ) {
        $_thumb_type  = false !== $_thumb_id ? 'custom' : $_thumb_type;
      }
      //IF no custom thumb id :
      //1) check if has thumbnail
      //2) check attachements
      //3) default thumb
      else {
        if ( has_post_thumbnail( $post_id ) ) {
          $_thumb_id    = get_post_thumbnail_id( $post_id );
          $_thumb_type  = false !== $_thumb_id ? 'thumb' : $_thumb_type;
        } else {
          $_thumb_id    = $this -> czr_fn_get_id_from_attachment( $post_id );
          $_thumb_type  = false !== $_thumb_id ? 'attachment' : $_thumb_type;
        }
        if ( ! $_thumb_id || empty( $_thumb_id ) ) {
          $_thumb_id    = esc_attr( CZR_utils::$inst->czr_fn_opt( 'tc_post_list_default_thumb' ) );
          $_thumb_type  = ( false !== $_thumb_id && ! empty($_thumb_id) ) ? 'default' : $_thumb_type;
        }
      }
      $_thumb_id = ( ! $_thumb_id || empty($_thumb_id) || ! is_numeric($_thumb_id) ) ? false : $_thumb_id;

      //update_post_meta($post_id, $meta_key, $meta_value, $prev_value);
      update_post_meta( $post_id , 'tc-thumb-fld', compact( "_thumb_id" , "_thumb_type" ) );
      if ( $_return )
        return apply_filters( 'tc_set_thumb_info' , compact( "_thumb_id" , "_thumb_type" ), $post_id );
    }//end of fn


    private function czr_fn_get_id_from_attachment( $post_id ) {
      //define a filtrable boolean to set if attached images can be used as thumbnails
      //1) must be a non single post context
      //2) user option should be checked in customizer
      $_bool = 0 != esc_attr( CZR_utils::$inst->czr_fn_opt( 'tc_post_list_use_attachment_as_thumb' ) );
      if ( ! is_admin() )
        $_bool == ! CZR_post::$instance -> czr_fn_single_post_display_controller() && $_bool;
      if ( ! apply_filters( 'tc_use_attachement_as_thumb' , $_bool ) )
        return;

      //Case if we display a post or a page
      if ( 'attachment' != get_post_type( $post_id ) ) {
        //look for the last attached image in a post or page
        $tc_args = apply_filters('tc_attachment_as_thumb_query_args' , array(
            'numberposts'             =>  1,
            'post_type'               =>  'attachment',
            'post_status'             =>  null,
            'post_parent'             =>  $post_id,
            'post_mime_type'          =>  array( 'image/jpeg' , 'image/gif' , 'image/jpg' , 'image/png' ),
            'orderby'                 => 'post_date',
            'order'                   => 'DESC'
          )
        );
        $attachments              = get_posts( $tc_args );
      }

      //case were we display an attachment (in search results for example)
      elseif ( 'attachment' == get_post_type( $post_id ) && wp_attachment_is_image( $post_id ) ) {
        $attachments = array( get_post( $post_id ) );
      }

      if ( ! isset($attachments) || empty($attachments ) )
        return;
      return isset( $attachments[0] ) && isset( $attachments[0] -> ID ) ? $attachments[0] -> ID : false;
    }//end of fn



    /**********************
    * THUMBNAIL VIEW
    **********************/
    /**
    * Display or return the thumbnail view
    * @param : thumbnail model (img, width, height), layout value, echo bool
    * @package Customizr
    * @since Customizr 3.0.10
    */
    function czr_fn_render_thumb_view( $_thumb_model , $layout = 'span3', $_echo = true ) {
      if ( empty( $_thumb_model ) )
        return;
      //extract "tc_thumb" , "tc_thumb_height" , "tc_thumb_width"
      extract( $_thumb_model );
      $thumb_img        = ! isset( $_thumb_model) ? false : $tc_thumb;
      $thumb_img        = apply_filters( 'tc_post_thumb_img', $thumb_img, CZR_utils::czr_fn_id() );
      if ( ! $thumb_img )
        return;

      //handles the case when the image dimensions are too small
      $thumb_size       = apply_filters( 'tc_thumb_size' , CZR_init::$instance -> tc_thumb_size, CZR_utils::czr_fn_id()  );
      $no_effect_class  = ( isset($tc_thumb) && isset($tc_thumb_height) && ( $tc_thumb_height < $thumb_size['height']) ) ? 'no-effect' : '';
      $no_effect_class  = ( esc_attr( CZR_utils::$inst->czr_fn_opt( 'tc_center_img') ) || ! isset($tc_thumb) || empty($tc_thumb_height) || empty($tc_thumb_width) ) ? '' : $no_effect_class;

      //default hover effect
      $thumb_wrapper    = sprintf('<div class="%5$s %1$s"><div class="round-div"></div><a class="round-div %1$s" href="%2$s" title="%3$s"></a>%4$s</div>',
                                    implode( " ", apply_filters( 'tc_thumbnail_link_class', array( $no_effect_class ) ) ),
                                    get_permalink( get_the_ID() ),
                                    esc_attr( strip_tags( get_the_title( get_the_ID() ) ) ),
                                    $thumb_img,
                                    implode( " ", apply_filters( 'tc_thumb_wrapper_class', array('thumb-wrapper') ) )
      );

      $thumb_wrapper    = apply_filters_ref_array( 'tc_post_thumb_wrapper', array( $thumb_wrapper, $thumb_img, CZR_utils::czr_fn_id() ) );

      //cache the thumbnail view
      $html             = sprintf('<section class="tc-thumbnail %1$s">%2$s</section>',
        apply_filters( 'tc_post_thumb_class', $layout ),
        $thumb_wrapper
      );
      $html = apply_filters_ref_array( 'tc_render_thumb_view', array( $html, $_thumb_model, $layout ) );
      if ( ! $_echo )
        return $html;
      echo $html;
    }//end of function

    /**********************
    * HELPER CALLBACK
    **********************/
    /**
    * hook wp_get_attachment_image_attributes
    * Get rid of the srcset attribute (responsive images)
    * @param $attr array of image attributes
    * @return  array of image attributes
    *
    * @package Customizr
    * @since Customizr 3.4.16
    */
    function czr_fn_remove_srcset_attr( $attr ) {
      if ( isset( $attr[ 'srcset' ] ) ) {
        unset( $attr['srcset'] );
        //to ensure a "local" removal we have to remove this filter callback, so it won't hurt
        //responsive images sitewide
        remove_filter( current_filter(), array( $this, __FUNCTION__ ) );
      }
      return $attr;
    }

    /**********************
    * SETTER CALLBACK
    **********************/
    /**
    * hook tc_post_thumb_inline_style
    * Replace default widht:auto by width:100%
    * @param array of args passed by apply_filters_ref_array method
    * @return  string
    *
    * @package Customizr
    * @since Customizr 3.2.6
    */
    function czr_fn_change_thumb_inline_css( $_style, $image, $_filtered_thumb_size) {
      //conditions :
      //note : handled with javascript if tc_center_img option enabled
      $_bool = array_product(
        array(
          ! esc_attr( CZR_utils::$inst->czr_fn_opt( 'tc_center_img') ),
          false != $image,
          ! empty($image),
          isset($_filtered_thumb_size['width']),
          isset($_filtered_thumb_size['height'])
        )
      );
      if ( ! $_bool )
        return $_style;

      $_width     = $_filtered_thumb_size['width'];
      $_height    = $_filtered_thumb_size['height'];
      $_new_style = '';
      //if we have a width and a height and at least on dimension is < to default thumb
      if ( ! empty($image[1])
        && ! empty($image[2])
        && ( $image[1] < $_width || $image[2] < $_height )
        ) {
          $_new_style           = sprintf('min-width:%1$spx;min-height:%2$spx;max-width: none;width: auto;max-height: none;', $_width, $_height );
      }
      if ( empty($image[1]) || empty($image[2]) )
        $_new_style             = sprintf('min-width:%1$spx;min-height:%2$spx;max-width: none;width: auto;max-height: none;', $_width, $_height );
      return $_new_style;
    }

}//end of class
endif;

?>