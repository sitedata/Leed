<?php

/*
 @nom: Feed
 @auteur: Idleman (idleman@idleman.fr)
 @description: Classe de gestion des flux RSS/ATOM
 */

class Feed extends SQLiteEntity{

	protected $id,$name,$url,$events=array(),$description,$website,$folder,$lastupdate;
	protected $TABLE_NAME = 'feed';
	protected $CLASS_NAME = 'Feed';
	protected $object_fields = 
	array(
		'id'=>'key',
		'name'=>'string',
		'description'=>'longstring',
		'website'=>'longstring',
		'url'=>'longstring',
		'lastupdate'=>'string',
		'folder'=>'integer'
	);

	function __construct($name=null,$url=null){
		$this->name = $name;
		$this->url = $url;
		parent::__construct();
	}

	function getInfos(){
		$xml = @simplexml_load_file($this->url);
		if($xml!=false){
			$this->name = array_shift ($xml->xpath('channel/title'));
			$this->description = array_shift ($xml->xpath('channel/description'));
			$this->website = array_shift ($xml->xpath('channel/link'));
		}
	}

	function parse2(){

		/*
		//TODO parser a travers un proxy

		 $proxy = getenv("HTTP_PROXY"); 
		
		 if (strlen($proxy) > 1) { 
		 $r_default_context = stream_context_get_default (array 
		                    ('http' => array( 
		                     'proxy' => $proxy, 
		                     'request_fulluri' => True, 
		                     'header'=>sprintf('Authorization: Basic %s',base64_encode('login:password'))
		                    ), 
		                ) 
		            ); 
		  libxml_set_streams_context($r_default_context); 
		      } 
		*/

		$xml = @simplexml_load_file($this->url,"SimpleXMLElement",LIBXML_NOCDATA);

		if(is_object($xml)){

			if(trim($this->name=='')) $this->name = (isset($xml->title)?$xml->title:$xml->channel->title);
			$this->description = $xml->channel->description;
			$this->website = $xml->channel->link;

			if(trim($this->website)=='') $this->website = (isset($xml->link[1]['href'])?$xml->link[1]['href']:$xml->link['href']);
			if(trim($this->description)=='') $this->description = $xml->subtitle;

			$eventManager = new Event();
			$items = $xml->xpath('//item');
			if(count($items)==0) $items = $xml->entry;
			$nonParsedEvents = array();
			foreach($items as $item){

				//Deffinition du GUID : 
				$guid = (trim($item->guid)!=''?$item->guid:$item->link['href']);
				$guid = (trim($guid)!=''?$guid:$item->link);
				$alreadyParsed = $eventManager->rowCount(array('guid'=>htmlentities($guid)));
				
				if($alreadyParsed==0){
					$event = new Event($guid,$item->title);
					$namespaces = $item->getNameSpaces(true);
					if(isset($namespaces['dc'])){ 
						$dc = $item->children($namespaces['dc']);
						$event->setCreator($dc->creator);
						$event->setPubdate($dc->date);
						if($event->getPubdate()=='') $event->setPubdate($dc->pubDate);
					}


					if(trim($event->getPubdate())=='')
						$event->setPubdate($item->pubDate);

					if(trim($event->getPubdate())=='')
						$event->setPubdate($item->date);

					if(trim($event->getPubdate())=='')
						$event->setPubdate($item->published);

					if(trim($event->getPubdate())=='')
						$event->setPubdate($item->updated);

					if(trim($event->getCreator())=='')
						$event->setCreator($item->creator);

					if(trim($event->getCreator())=='')
						$event->setCreator($item->author);

					if(trim($event->getCreator())=='')
						$event->setCreator($item->author->name);

					if(trim($event->getCreator())=='')
						$event->setCreator('Anonyme');
					
					$event->setDescription($item->description);
				
					$event->setLink($item->link);

					if(trim($event->getLink())=='')
					$event->setLink($item->link['href']);

					$event->setContent($item->content);

					if(trim($event->getContent())=='' && isset($namespaces['content']))
						$event->setContent($item->children($namespaces['content']));
					
					
					if(trim($event->getDescription())=='')
						$event->setDescription(substr($event->getContent(),0,300).'...<br><a href="'.$event->getLink().'">Lire la suite de l\'article</a>');
					

					if(trim($event->getContent())=='')
						$event->setContent($event->getDescription());
					

						/*//Tentative de detronquage si la description existe
						if($event->getDescription()!=''){
							  // preg_match('#<a(.+)href=(.+)>#isU', $event->getDescription(), $matches);
							 //echo var_dump($matches);

							//Récup de l'article dans son contexte
							 $allContent = simplexml_load_file($event->getGuid());
							 if($allContent!=false){
							 	foreach($xml->xpath('//item') as $div){
							 		echo var_dump($div);
							 		echo '<hr>';
							 	}
							 }
							

						}
						*/
					
					
					$event->setCategory($item->category);
					
					$event->setFeed($this->id);
					$event->setUnread(1);
					$nonParsedEvents[] = $event;
					unset($event);
				}

			}

			if(count($nonParsedEvents)!=0) $eventManager->massiveInsert($nonParsedEvents);

			$result = true;
				
		}else{
			$this->name = 'Flux invalide';
			$this->description = 'Impossible de se connecter au flux demand&eacute, peut &ecirc;tre est il en maintenance?';
			$result = false;
		}
			$this->lastupdate = $_SERVER['REQUEST_TIME'];
			$this->save();
			return $result;
	}


	function parse(){

		require_once("SimplePie.class.php");
		$feed = new SimplePie();
		$feed->set_feed_url($this->url);
		$feed->init();
		$feed->handle_content_type();

		$this->name = $feed->get_title();
		$this->website = $feed->get_link();
		$this->description = $feed->get_description();

		$items = $feed->get_items();
		$eventManager = new Event();
			
		$nonParsedEvents = array();
		foreach($items as $item){

				//Deffinition du GUID : 
			
				$alreadyParsed = $eventManager->rowCount(array('guid'=>htmlentities($item->get_id())));
				

				if($alreadyParsed==0){
					$event = new Event();
					$event->setGuid($item->get_id());
					$event->setTitle($item->get_title());
					$event->setPubdate($item->get_date());
					$event->setCreator( (is_object($item->get_author())?$item->get_author()->name:'Anonyme') );
				
					$event->setLink($item->get_permalink());
					$event->setContent($item->get_content());
					$event->setDescription($item->get_description());
					
					if(trim($event->getDescription())=='')
						$event->setDescription(substr($event->getContent(),0,300).'...<br><a href="'.$event->getLink().'">Lire la suite de l\'article</a>');
					
					if(trim($event->getContent())=='')
						$event->setContent($event->getDescription());
					
					$event->setCategory($item->get_category());
					$event->setFeed($this->id);
					$event->setUnread(1);
					$nonParsedEvents[] = $event;
					unset($event);
				}

		}

			if(count($nonParsedEvents)!=0) $eventManager->massiveInsert($nonParsedEvents);

			$result = true;
				
		
			$this->lastupdate = $_SERVER['REQUEST_TIME'];
			$this->save();
			return $result;
	}


	function removeOldEvents($maxEvent){
		$eventManager = new Event();
		$limit = $eventManager->rowCount(array('feed'=>$this->id))-$maxEvent;
		if ($limit>0) $this->exec("DELETE FROM Event WHERE id in(SELECT id FROM Event WHERE feed=".$this->id."  AND favorite!=1 ORDER BY pubDate ASC LIMIT ".($limit>0?$limit:0).");");
	}
	
	function setId($id){
		$this->id = $id;
	}

	function getDescription(){
		return stripslashes($this->description);
	}

	function setDescription($description){
		$this->description = html_entity_decode($description);
	}
	function getWebSite(){
		return $this->website;
	}

	function setWebSite($website){
		$this->website = $website;
	}

	function getId(){
		return $this->id;
	}

	function getUrl(){
		return $this->url;
	}

	function setUrl($url){
		$this->url = $url;
	}

	function getName(){
		return (trim($this->name)!='' ? $this->name:$this->url);
	}

	function setName($name){
		$this->name = html_entity_decode($name);
	}


	function getEvents($start=0,$limit=10000,$order,$columns='*'){
		$eventManager = new Event();
		$events = $eventManager->loadAllOnlyColumn($columns,array('feed'=>$this->getId()),$order,$start.','.$limit);
		return $events;
	}

	function countUnreadEvents(){
		$unreads = array();
		$results = Feed::customQuery("SELECT COUNT(event.id), feed.id FROM event INNER JOIN feed ON (event.feed = feed.id) WHERE event.unread = '1' GROUP BY feed.id") ;
		while($item = $results->fetchArray()){
			$unreads[$item[1]] = $item[0];
		}
		return $unreads;
	}

	function getFeedsPerFolder(){
		$feeds = array();
		$results = Feed::customQuery("SELECT feed.name AS name, feed.id   AS id, feed.url  AS url, folder.id AS folder FROM feed INNER JOIN folder ON ( feed.folder = folder.id ) ORDER BY feed.name ;");
		while($item = $results->fetchArray()){
			$feeds[$item['folder']][$item['id']]['id'] = $item['id'];
			$feeds[$item['folder']][$item['id']]['name'] = html_entity_decode($item['name']);
			$feeds[$item['folder']][$item['id']]['url'] = $item['url'];
		}
		return $feeds;
	}


	function getFolder(){
		return $this->folder;
	}

	function setFolder($folder){
		$this->folder = $folder;
	}

	function getLastupdate(){
		return $this->lastUpdate;
	}

	function setLastupdate($lastupdate){
		$this->lastupdate = $lastupdate;
	}
	


}

?>