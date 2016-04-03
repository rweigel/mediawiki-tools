<?php
error_reporting (1);
$wgExtensionFunctions[] = 'efExtensionWrapperInsert2Function_Setup';
$wgHooks['LanguageGetMagic'][] = 'efExtensionWrapperInsert2Function_Magic';
 
function efExtensionWrapperInsert2Function_Setup() {
	 global $wgParser;
	 $wgParser->setFunctionHook( 'insert2', 'efExtensionWrapperInsert2Function_Render' );
}
 
function efExtensionWrapperInsert2Function_Magic( &$magicWords, $langCode ) {
	 $magicWords['insert2'] = array( 0, 'insert2' );
	 return true;
}
 
function efExtensionWrapperInsert2Function_Render( &$parser, $param1 = '' , $param2 = '') {
	 # The input parameters are wikitext with templates expanded.  The output should be wikitext too
	 
       $content = file_get_contents($param1);
	
       if ($content == false) {
	    $source = fopen($param1, "r");
       	    $maxsizeKB = 1000; # Max file size before the download stops
  	       $maxsize   = 1024 * $maxsizeKB;
     		  $chunkSize = 2048; # Fetch 2 KB in each iteration

  	     $length = 0;
    	     while (($a = fread($source, $chunkSize)) && ($length <= $maxsize)) {
     	   	$length = $length + $chunkSize;
 		$content = $content + $a;
  	   }
	   fclose($source);
          if ($length == 0) {$content = false;}
       }
			
       if ($content !== false) {
	   return "<addhtml title='$param2' id='$param2'><html>$content</html></addhtml>";
        } else {
           return "Could not read file: $param1";
     }
}
?>
