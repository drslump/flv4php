<?php

include_once 'FLV/FLV.php';

class MyFLV extends FLV {
	
	/**
	 * On audio-only files the frame index will use this as minimum gap 
	 */
	private $audioFrameGap = 3;
	
	private $origMetaOfs = 0;
	private $origMetaSize = 0;
	private $origMetaData;
	private $compMetaData;
	
	
	function computeMetaData()
	{
		$this->compMetaData = array();
		$this->compMetaData['metadatacreator'] = 'FLV Tools for PHP v0.1 by DrSlump';
		$this->compMetaData['metadatadate'] = gmdate('Y-m-d\TH:i:s') . '.000Z';
		$this->compMetaData['keyframes'] = array();
		$this->compMetaData['keyframes']['filepositions'] = array();
		$this->compMetaData['keyframes']['times'] = array();
		
		$this->origMetaOfs = 0;
		$this->origMetaSize = 0;
		$this->origMetaData = null;
		
		$skipTagTypes = array();
		while ($tag = $this->getTag( $skipTagTypes ))
		{
			// pre-calculate the timestamp as seconds
	    	$ts = number_format($tag->timestamp/1000, 3);
	    
	    	if ($tag->timestamp > 0)
		    	$this->compMetaData['lasttimestamp'] = $ts;
	    
	    	switch ($tag->type)
	    	{
	        	case FLV_Tag::TYPE_VIDEO :
	        	        	
	           		//Optimization, extract the frametype without analyzing the tag body
	           		if ((ord($tag->body[0]) >> 4) == FLV_Tag_Video::FRAME_KEYFRAME)
	           		{
						$this->compMetaData['keyframes']['filepositions'][] = $this->getTagOffset();
						$this->compMetaData['keyframes']['times'][] = $ts;
	           		}
	           	
	            	if ( !in_array(FLV_Tag::TYPE_VIDEO, $skipTagTypes) )
	            	{
	                	$this->compMetaData['width'] = $tag->width;
	                	$this->compMetaData['height'] = $tag->height;
	                	$this->compMetaData['videocodecid'] = $tag->codec;
						//Processing one video tag is enough               
	            		array_push( $skipTagTypes, FLV_Tag::TYPE_VIDEO );
	            	}
	            
	        		break;
	        	
	        	case FLV_Tag::TYPE_AUDIO :
	        	
					//Save audio frame positions when there is no video 
	        		if (!$flv->hasVideo && $ts - $oldTs > $this->audioFrameGap)
	        		{
		        		$this->compMetaData['keyframes']['filepositions'][] = $this->getTagOffset();
		        		$this->compMetaData['keyframes']['times'][] = $ts;
		        		$oldTs = $ts;
	        		}
	        	
	            	if ( !in_array( FLV_Tag::TYPE_AUDIO, $skipTagTypes) )
	            	{
		            	$this->compMetaData['audiocodecid'] = $tag->codec;
		            	$this->compMetaData['audiofreqid'] = $tag->frequency;
		            	$this->compMetaData['audiodepthid'] = $tag->depth;
		            	$this->compMetaData['audiomodeid'] = $tag->mode;
		            
						//Processing one audio tag is enough
	            		array_push( $skipTagTypes, FLV_Tag::TYPE_AUDIO );
	            	}
					
	        		break;
					
	        	case FLV_Tag::TYPE_DATA :
	            	if ($tag->name == 'onMetaData')
	            	{
	            		$this->origMetaOfs = $this->getTagOffset();
	            		$this->origMetaSize = $tag->size + self::TAG_HEADER_SIZE;
	            		$this->origMetaData = $tag->value;
	            	}
	        		break;
	    	}
	    
	    	//Does this actually help with memory allocation?
	    	unset($tag);
		}
		
		if (! empty($this->compMetaData['keyframes']['times']))
			$this->compMetaData['lastkeyframetimestamp'] = $this->compMetaData['keyframes']['times'][ count($this->compMetaData['keyframes']['times'])-1 ];
	
		$this->compMetaData['duration'] = $this->compMetaData['lasttimestamp'];
		
		return $this->compMetaData;
	}
	
	function setMetaData( $metadata, $origMetaOfs = 0, $origMetaSize = 0 )
	{
		$this->compMetaData = $metadata;
		$this->origMetaOfs = $origMetaOfs;
		$this->origMetaSize = $origMetaSize;
	}
	
	function getMetaData()
	{
		if (! is_array($this->origMetaData))
			return $this->compMetaData;
		else
			return array_merge( $this->origMetaData, $this->compMetaData );
	}
	
	
	function play( $from = 0 )
	{
		fseek($this->fp, 0);
		
		// get original file header just in case it has any special flag
		echo fread($this->fp, $this->bodyOfs + 4);
		
		// output the metadata if available
		$meta = $this->getMetaData();
		if (! empty($meta))
		{
			//serialize the metadata as an AMF stream
			include_once 'FLV/Util/AMFSerialize.php';
			$amf = new FLV_Util_AMFSerialize();

			$serMeta = $amf->serialize('onMetaData');
			$serMeta.= $amf->serialize($meta);

			//Data tag mark
			$out = pack('C', FLV_Tag::TYPE_DATA);
			//Size of the data tag (BUG: limited to 64Kb)
			$out.= pack('Cn', 0, strlen($serMeta));
			//Timestamp
			$out.= pack('N', 0);
			//StreamID
			$out.= pack('Cn', 0, 0);
			
			echo $out;
			echo $serMeta;
			
			// PrevTagSize for the metadata
			echo pack('N', strlen($serMeta) + strlen($out) );
		}
		
		$chunkSize = 4096;
		$skippedOrigMeta = empty($this->origMetaSize);
		while (! feof($this->fp))
		{
			// if the original metadata is pressent and not yet skipped...
			if (! $skippedOrigMeta)
			{				
				$pos = ftell($this->fp);
			
				// check if we are going to output it in this loop step
				if ( $pos <= $this->origMetaOfs &&
					 $pos + $chunkSize > $this->origMetaOfs )
				{
					// output the bytes just before the original metadata tag
					if ($this->origMetaOfs - $pos > 0)
						echo fread($this->fp, $this->origMetaOfs - $pos);
					
					// position the file pointer just after the metadata tag
					fseek($this->fp, $this->origMetaOfs + $this->origMetaSize);
					
					$skippedOrigMeta = true;
					continue;
				}
			}
			
			echo fread($this->fp, $chunkSize);
		}
	}
}



$flv = new MyFLV();
try {
	$flv->open( 'test1.flv' );
} catch (Exception $e) {
	die("<pre>The following exception was detected while trying to open a FLV file:\n" . $e->getMessage() . "</pre>");
}

//Here we should cache the result and use ->setMetaData() instead
$start = microtime(true);
$flv->computeMetaData();
$end = microtime(true);
//echo "<hr/>EXTRACT METADATA PROCESS TOOK " . number_format(($end-$start), 2) . " seconds<br/>";


//echo "<pre>" . print_r($flv->getMetaData(), true) . "</pre>";
header('Content-type: flv-application/octet-stream');
header('Content-Disposition: attachment; filename="out.flv"');
$flv->play(0);


$flv->close();	



?>