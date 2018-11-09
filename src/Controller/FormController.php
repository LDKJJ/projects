<?php

namespace Drupal\generate_ajax_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Class FormController.
 */
class FormController extends ControllerBase {

  /**
   * Generate.
   *
   * @return object json
   *   Return Response JSON.
   */
  public function generate(Request $request) 
  {
    $fields = array();
    $nid = $request->get('nid');
    $ntype = $request->get('ntype');
    $clname = $request->get('clname');
    $listfields = $request->get('listfields');

    $query = \Drupal::entityQuery('node')
    ->condition('type', $ntype);
    if ($ntype != 'recrutement_infos') {
      $query->condition('nid', $nid);
    }
    $query->condition('status', 1);
    $nids = $query->execute();

    $node = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);

    $fields_target = array_values($node)[0]->get($clname)->getValue();

    foreach($fields_target as $key => $item){
      $target_id = $item['value'];
      $fc = \Drupal\field_collection\Entity\FieldCollectionItem::load($target_id);

      $fields [] = array(
        'name'  => $this->cleanString($fc->get($listfields['name'])->getValue()[0]['value']) . '-' . $target_id,
        'label' => $fc->get($listfields['name'])->getValue()[0]['value'],
        'type'  => $fc->get($listfields['type'])->getValue()[0]['value'],
        'value' => ( !$fc->get($listfields['values'])->isEmpty() ? $s = str_replace("\r\n", '&', $fc->get($listfields['values'])->getValue()[0]['value']) : '' ),
        'rules' => trim($fc->get($listfields['rules'])->getValue()[0]['value'])
      );
    }

    return new JsonResponse($fields);
  }

  /**
   * Generate.
   *
   * @return object json
   *   Return Response JSON.
   */
  public function send(Request $request)
  {
    try{
      header("Content-type: application/json; charset=utf-8");
      $listfields = json_decode($request->get('listfields'), TRUE);
      $ntype = $request->get('ntype');
      $data = array();
      $errors = array();

      foreach ( $request->request->all() as $key => $value ) {
        $group_errors = 0;
        if ( $key == "nid" OR $key == 'title' OR $key == "ntype" OR $key == "listfields" ) {
          continue;
        }

        $fc = \Drupal\field_collection\Entity\FieldCollectionItem::load(explode('-', $key)[1]);
        $label_name = trim(addslashes($fc->get($listfields['name'])->getValue()[0]['value']));
        $field_type = $fc->get($listfields['type'])->getValue()[0]['value'];
        /*---------------------- Check Form Rules ------------------------*/
        $rules = explode( '|', trim(addslashes($fc->get($listfields['rules'])->getValue()[0]['value'])) );  

        /*------------------------- Validate Empty Field ---------------------------*/
        if (in_array( 'required', $rules) AND empty($value)) {
          $errors []= array( 
            'message' => $this->t('le champ <span class="bold-text">' . $label_name . '</span> est requis') 
          );
          $group_errors++;
        }
        /*------------------------- Validate Field Email ---------------------------*/
        if (!empty($value) AND in_array('email', $rules) AND !\Drupal::service('email.validator')->isValid($value)) {
          $errors []= array( 
            'message' => $this->t('le champ <span class="bold-text">' . $label_name . '</span> n\'est pas valide') 
          );
          $group_errors++;
        }
        /*---------------------- Validate Field Phone Number -----------------------*/
        $regex_phone = '/^[0-9]/';
        if (!empty($value) AND in_array('number', $rules) AND preg_match($regex_phone, $value) != 1)
        {
          $errors []= array( 
            'message' => $this->t('le champ <span class="bold-text">' . $label_name . '</span> n\'est pas valide') 
          );
          $group_errors++;
        }
        if ($group_errors == 0) {
          $data []= array( 
            "value" => utf8_encode(trim(addslashes($value))),
            "label" => $label_name,
            "type"  => $field_type
          );
        }
      }
      /*--------------------- Upload Dynamic Files ---------------------*/
      $uploadDir = \Drupal::service('file_system')->realpath(file_default_scheme() . "://") . '/uploads';

      foreach( $_FILES as $key => $value ) {
        $fc = \Drupal\field_collection\Entity\FieldCollectionItem::load(explode( '-', $key )[1]);
        $label_name = $fc->get($listfields['name'])->getValue()[0]['value'];
        $field_type = $fc->get($listfields['type'])->getValue()[0]['value'];
        $rules = explode( '|', trim(addslashes($fc->get($listfields['rules'])->getValue()[0]['value'])) );
        /*------------------------- Validate Empty Field ---------------------------*/
        if (in_array( 'required', $rules) AND empty($value['size'])) {
          $errors []= array( 
            'message' => $this->t('le champ <span class="bold-text">' . $label_name . '</span> est requis') 
          );
          $group_errors++;
        }
        $fileExt = pathinfo( $value["name"], PATHINFO_EXTENSION );
        $filenameHash = md5( $value["name"] . uniqid( rand(), true) );
        if (move_uploaded_file($value["tmp_name"], $uploadDir . '/' . $filenameHash .'.'. $fileExt)) {
          $attachment = array(
            'filepath' => $uploadDir .'/'. $filenameHash .'.' .$fileExt,
            'filename' => $value["name"],
            'filemime' => 'application/' . $fileExt
          );

          $params['attachments'][] = $attachment;

          $data []= array( 
            'value' => $value['name'] . '&' . $filenameHash .'.'. $fileExt, 
            "label" => $label_name,
            "type"  => $field_type
          );
        }
      }

      if ( count( $errors ) == 0 ) {

        $data_json = json_encode( $data, JSON_UNESCAPED_UNICODE );

        $data = array(
          'nid'   => $request->get('nid'),
          'ntype' => $ntype,
          'value' => $data_json
        );

        /*------------------- Store data in database --------------------*/
        $conn = \Drupal\Core\Database\Database::getConnection();
        if ($conn->insert('data_json')->fields($data)->execute()) {
          if ($ntype == 'recrutement_offre') {
            $params['title'] = $title = $request->get('title');
            $params['data']  = $data;
            /*-------------- Send Mail Candidature ----------------*/
            $mailManager = \Drupal::service('plugin.manager.mail');
            $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
            $module = "generate_ajax_form";
            $key = 'send-candidature';
            $to = \Drupal::config('system.site')->get('mail');
            $send = true;
            $result = $mailManager->mail($module, $key, $to, $langcode, $params, null, $send);
            $message = $this->t('Merci! votre candidature a bien été envoyée nous reviendrons vers vous dans les plus brefs délais.');
          } else {
            $message = $this->t('Félicitations vous êtes impliqué dans ce jeu.');
          }
          return new JsonResponse([
            'success' => [ 'message' => $message ] 
          ]);
        }
      } else {
        return new JsonResponse([
          'errors' => $errors
        ]);
      }
    } catch(Exception $msg) {
      return new JsonResponse(
        [ 'errors' => [ 'message' => $this->t('Oops! une erreur s\'est produite lors de votre demande de participation au jeux') ]
      ]);
    }
  }

  public function cleanString($text)
  {
    $utf8 = array(
      '/[áàâãªä]/u'   =>   'a',
      '/[ÁÀÂÃÄ]/u'    =>   'A',
      '/[ÍÌÎÏ]/u'     =>   'I',
      '/[íìîï]/u'     =>   'i',
      '/[éèêë]/u'     =>   'e',
      '/[ÉÈÊË]/u'     =>   'E',
      '/[óòôõºö]/u'   =>   'o',
      '/[ÓÒÔÕÖ]/u'    =>   'O',
      '/[úùûü]/u'     =>   'u',
      '/[ÚÙÛÜ]/u'     =>   'U',
      '/ç/'           =>   'c',
      '/Ç/'           =>   'C',
      '/ñ/'           =>   'n',
      '/Ñ/'           =>   'N',
      '/–/'           =>   '-', // UTF-8 hyphen to "normal" hyphen
      '/[’‘‹›‚]/u'    =>   ' ', // Literally a single quote
      '/[“”«»„]/u'    =>   ' ', // Double quote
      '/ /'           =>   '_', // nonbreaking space (equiv. to 0x160)
    );

    return strtolower(preg_replace(array_keys($utf8), array_values($utf8), $text));
  }

}