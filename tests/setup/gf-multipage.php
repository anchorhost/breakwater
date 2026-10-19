<?php
// Multi-page Gravity Forms fixture for tests/multipage.mjs: three pages, Save and Continue on,
// and the pages /gf-multi-ajax/ and /gf-multi-post/. Run with wp eval-file in the sandbox.
$form = [
  'title' => 'Multipage', 'labelPlacement' => 'top_label', 'button' => [ 'type' => 'text', 'text' => 'Submit' ],
  'save' => [ 'enabled' => true, 'button' => [ 'type' => 'link', 'text' => 'Save and Continue Later' ] ],
  'pagination' => [ 'type' => 'steps', 'pages' => [ 'Contact', 'Project', 'Confirm' ] ],
  'fields' => [
    [ 'id' => 1, 'type' => 'name', 'label' => 'Name', 'nameFormat' => 'simple', 'pageNumber' => 1 ],
    [ 'id' => 2, 'type' => 'email', 'label' => 'Email', 'pageNumber' => 1 ],
    [ 'id' => 10, 'type' => 'page', 'pageNumber' => 2, 'nextButton' => [ 'type' => 'text', 'text' => 'Next' ], 'previousButton' => [ 'type' => 'text', 'text' => 'Previous' ] ],
    [ 'id' => 3, 'type' => 'textarea', 'label' => 'Message', 'pageNumber' => 2 ],
    [ 'id' => 11, 'type' => 'page', 'pageNumber' => 3, 'nextButton' => [ 'type' => 'text', 'text' => 'Next' ], 'previousButton' => [ 'type' => 'text', 'text' => 'Previous' ] ],
    [ 'id' => 4, 'type' => 'consent', 'label' => 'Consent', 'checkboxLabel' => 'I agree', 'pageNumber' => 3, 'inputs' => [ ['id'=>'4.1','label'=>'Consent'], ['id'=>'4.2','label'=>'Text','isHidden'=>true], ['id'=>'4.3','label'=>'Description','isHidden'=>true] ] ],
  ],
];
$id = GFAPI::add_form( $form );
echo "form $id\n";
foreach ( [ 'true' => 'gf-multi-ajax', 'false' => 'gf-multi-post' ] as $ajax => $slug ) {
  echo wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $slug, 'post_content' => "[gravityform id=\"$id\" ajax=\"$ajax\" title=\"false\"]" ] ), " $slug\n";
}
