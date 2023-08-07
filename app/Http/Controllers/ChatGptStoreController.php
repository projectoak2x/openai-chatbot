<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreChatRequest;
use App\Models\Chat;
use Illuminate\Support\Facades\Auth;
use OpenAI\Laravel\Facades\OpenAI;
use Log;

class ChatGptStoreController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(StoreChatRequest $request, string $id = null)
    {
        $messages[] = ['role' => 'system', 'content' => 'The current UTC date and time now is ' . gmdate('Y-m-d H:i:s') . " . Using your web access and web scraping capabilities, please find the most recent and reliable sources online regarding the user questions. Please summarize your findings in a clear, concise manner for easy understanding."];

        if ($id) {
            $chat = Chat::findOrFail($id);
            $messages = $chat->context;
        }
        $counter = 1;
        $messages[] = ['role' => 'user', 'content' => $request->input('promt')];
        $response = getResponse($messages);
        while ($response->choices[0]->message->content==null&&$counter<=5){
            $jsonData = json_decode($response->choices[0]->message->functionCall->arguments);
            if($response->choices[0]->message->functionCall->name=='get_current_weather'){
                $messages[] = getWeather($jsonData);;
            }else if($response->choices[0]->message->functionCall->name=='web_search'){
                $messages[] = webSearch($jsonData);
            }
            else if($response->choices[0]->message->functionCall->name=='news_search'){
              $messages[] = getNews($jsonData);
            }
            else if($response->choices[0]->message->functionCall->name=='web_scraper'){
                $messages[] = webScrape($jsonData);
            }
            $response = getResponse($messages);
        }

        $filtered = array_filter($messages, function ($item) {
          return !(isset($item['name']) && $item['name'] === 'web_scraper');
        });
        
        $filtered[] = ['role' => 'assistant', 'content' => $response->choices[0]->message->content??"I can't find it anywhere on my web results."];
        $chat = Chat::updateOrCreate(
            [
                'id' => $id,
                'user_id' => Auth::id()
            ],
            [
                'context' => $filtered
            ]
        );

        return redirect()->route('chat.show', [$chat->id]);
    }

}

function getSummary($scrape_data){
  Log::info("summarizing");
  $response =  OpenAI::chat()->create([
    'model' => 'gpt-4-0613',
    'messages' => [['role' => 'system', 'content' => 'You are a scraper assistant that help retrieve data from a clean html data and returns all relavant information including link to.'],['role' => 'user', 'content' => $scrape_data]]
    ]);
    Log::info($response->choices[0]->message->content);
  return $response->choices[0]->message->content;
}

function getResponse($messages){
    $u_messages = $messages;
    $a_user = ['role' => 'user', 'content' => 'when searching if you need more information to provide a more complete answer Please scrape into the urls. Scrape a url only once. If encounter problem scraping url, try other urls from the web search.'];
    array_splice($u_messages, -1, 0, array($a_user));
    return OpenAI::chat()->create([
      'model' => 'gpt-4-0613',
      'messages' => $u_messages,
      "functions" => [
          [
              "name" => "web_search",
              "description"=>  "A search engine. useful for when you need to search the web. Please call the scrape function when searching for news.",
              "parameters"=>  [
                "type"=>  "object",
                "properties"=>  [
                  "query"=> [
                    "type"=>  "string",
                    "description"=> "The information needed to search"
              ]
              ],
                "required"=>  ["query"]
              ]
          ],
          [
              "name" => "get_current_weather",
              "description"=>  "Get the current weather in a given location.",
              "parameters"=>  [
                "type"=>  "object",
                "properties"=>  [
                  "location"=> [
                    "type"=>  "string",
                    "description"=> "The location need weather info"
              ]
              ],
                "required"=>  ["location"]
              ]
          ],
          // [
          //     "name" => "news_search",
          //     "description"=>  "News search engine.",
          //     "parameters"=>  [
          //       "type"=>  "object",
          //       "properties"=>  [
          //         "query"=> [
          //           "type"=>  "string",
          //           "description"=> "What news about"
          //     ]
          //     ],
          //       "required"=>  ["query"]
          //     ]
          // ],
          [
              "name" => "web_scraper",
              "description"=>  "A web scraper. useful for when you need to scrape websites for additional information. Most useful for when gathering information for news.",
              "parameters"=>  [
                "type"=>  "object",
                "properties"=>  [
                  "url"=> [
                    "type"=>  "string",
                    "description"=> "The web site url"
              ]
              ],
                "required"=>  ["url"]
              ]
          ]
          ],
  ]);
}

function getWeather($jsonData){
  Log::info("Using weather api");
  $location = $jsonData->location;
  $url = "http://api.weatherapi.com/v1/current.json?key=0191ce76160f4b5b9ad31403230408&&aqi=no&q=" .urlencode($location);
  $s_response = file_get_contents($url);
  Log::info(json_encode($s_response));
  return ['role' => 'function','name' => 'get_current_weather', 'content' => $s_response];
}

function getNews($jsonData){
  $query = $jsonData->query;

  $curl = curl_init();

  curl_setopt_array($curl, array(
    CURLOPT_URL => 'https://google.serper.dev/news',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => 'CURL_HTTP_VERSION_1_1',
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS =>'{"q":"'.$query.'","num":10}',
    CURLOPT_HTTPHEADER => array(
      'X-API-KEY: 228c19b0a498a82d61554ff2801c28b4c92e0145',
      'Content-Type: application/json'
    ),
  ));
  
  $s_response = curl_exec($curl);
  Log::info($s_response);
  $results = json_decode($s_response);
  $concat_results="";
  foreach ($results->news as $item) {
      $concat_results  .= ' Title: ' . $item->title . "\n";
      // $concat_results  .= ' Link: ' . $item->link . "\n";
      $concat_results  .= ' Date: ' . $item->date . "\n";
      $concat_results  .= ' Snippet: ' . $item->snippet . "\n\n";
  }
  Log::info("concat result");
  Log::info($concat_results);
  return ['role' => 'function','name' => 'news_search', 'content' => $concat_results];
}


function webSearch($jsonData){
  Log::info("Searching the web");
  $query = $jsonData->query;

  $curl = curl_init();

  curl_setopt_array($curl, array(
    CURLOPT_URL => 'https://google.serper.dev/search',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => 'CURL_HTTP_VERSION_1_1',
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS =>'{"q":"'.$query.'","num":5}',
    CURLOPT_HTTPHEADER => array(
      'X-API-KEY: 228c19b0a498a82d61554ff2801c28b4c92e0145',
      'Content-Type: application/json'
    ),
  ));
  
  $s_response = curl_exec($curl);
  Log::info($s_response);
  $results = json_decode($s_response);
  $concat_results="";
  if(property_exists($results, 'knowledgeGraph')){
    $concat_results= "knowledgeGraph: " . json_encode($results->knowledgeGraph) . "\n";
  }
  if(property_exists($results, 'answerBox')){
    $concat_results= "answerBox : " . json_encode($results->answerBox) . "\n";
  }
  $concat_results .= "Organic:";
  foreach ($results->organic as $item) {
      $concat_results  .= ' Title: ' . $item->title . "\n";
      $concat_results  .= ' Link: ' . $item->link . "\n";
      $concat_results  .= ' Snippet: ' . $item->snippet . "\n\n";
  }
  Log::info("concat result");
  Log::info($concat_results);
  return ['role' => 'function','name' => 'web_search', 'content' => $concat_results];
}


function webScrape($jsonData) {
    $url = $jsonData->url;
    // Create a new DOMDocument instance
    $dom = new \DOMDocument();

    // Load the HTML content from the URL

    Log::info("Scraping $url");
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_PROXY, 'http://6fe25a60306000f1500cb95cc2b58d05b3fe24aa:@proxy.zenrows.com:8001');
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    $html = curl_exec($ch);
    Log::info("Parsing");

    // Suppress HTML errors (optional)
    libxml_use_internal_errors(true);

    // Load the HTML content into the DOMDocument
    $dom->loadHTML($html);

    // Create a DOMXPath object to navigate the DOMDocument
    $xpath = new \DOMXPath($dom);

    // $linkTags = $xpath->query('//a');
    // foreach ($linkTags as $linkTag) {
    //     $href = $linkTag->getAttribute('href');
    //     $linkText = '[' . $linkTag->nodeValue . '](' . $href . ')';
    
    //     // create new text node for link
    //     $newLinkNode = $dom->createTextNode($linkText);
    
    //     // replace the old 'a' node with new text node
    //     $linkTag->parentNode->replaceChild($newLinkNode, $linkTag);
    // }

    // Remove all script tags from the DOMDocument
    $scriptTags = $xpath->query('//script');
    foreach ($scriptTags as $scriptTag) {
        $scriptTag->parentNode->removeChild($scriptTag);
    }

    // Remove all style tags from the DOMDocument
    $styleTags = $xpath->query('//style');
    foreach ($styleTags as $styleTag) {
        $styleTag->parentNode->removeChild($styleTag);
    }

    // Get the text content of the body
    $textContent = $dom->getElementsByTagName('body')->item(0)->textContent;

    // Clean up whitespace and remove unrelated info
    $lines = explode("\n", $textContent);
    $filteredText = '';

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip empty lines and lines with just a few characters
        if (empty($line) || strlen($line) < 5) {
            continue;
        }

        // Add relevant lines to the filtered text
        // You can add additional filtering conditions based on your specific needs
        $filteredText .= $line . "\n";
    }

    // $summary = getSummary(trim($textContent));

    $parse_result = trim($textContent) . "\n\n" . "Scrape Source URL: $url";

    Log::info($parse_result);

    // Clean up whitespace and return the text content
    return ['role' => 'function','name' => 'web_scraper', 'content' => $parse_result];
}
