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
        $messages[] = ['role' => 'system', 'content' => 'The current UTC date and time now is ' . gmdate('Y-m-d H:i:s')];
        if ($id) {
            $chat = Chat::findOrFail($id);
            $messages = $chat->context;
        }
        $messages[] = ['role' => 'user', 'content' => $request->input('promt')];
        $response = OpenAI::chat()->create([
            'model' => 'gpt-3.5-turbo-16k-0613',
            'messages' => $messages,
            "functions" => [
                [
                    "name" => "web_search",
                    "description"=>  "A search engine. useful for when you need to gather new information, latest, trending and upcoming. Also useful If you don't have information about the information asked.",
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
                ]
                ],
        ]);
        if($response->choices[0]->message->content==null){
            $jsonData = json_decode($response->choices[0]->message->functionCall->arguments);
            Log::info($response->choices[0]->message->functionCall->arguments);
            if($response->choices[0]->message->functionCall->name=='get_current_weather'){
                Log::info("Using weather api");
                $location = $jsonData->location;
                $url = "http://api.weatherapi.com/v1/current.json?key=0191ce76160f4b5b9ad31403230408&&aqi=no&q=" .urlencode($location);
                $s_response = file_get_contents($url);
                Log::info(json_encode($s_response));
                $messages[] = ['role' => 'function','name' => 'get_current_weather', 'content' => $s_response];
                $response = OpenAI::chat()->create([
                    'model' => 'gpt-3.5-turbo-16k-0613',
                    'messages' => $messages,
                    "functions" => [
                        [
                            "name" => "get_current_weather",
                            "description"=>  "Get the current weather in a given location. Returns Json data.",
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
                        ]
                    ],
                ]);
            }else if($response->choices[0]->message->functionCall->name=='web_search'){
                Log::info("Searching the web");
                $apiKey = 'AIzaSyCNKAVmTCelLTeAxPGq_ShbIGfdv6WRaV4';
                $cseID = '82a52554294294369';
                $query = $jsonData->query;
                $url = "https://www.googleapis.com/customsearch/v1?key=$apiKey&cx=$cseID&start=1&num=5&q=" .urlencode($query);
    
                $s_response = file_get_contents($url);
                $concat_results = "";
                $results = json_decode($s_response);
                foreach ($results->items as $item) {
                    $concat_results  .= 'Title: ' . $item->title . "\n";
                    $concat_results  .= 'Link: ' . $item->link . "\n";
                    $concat_results  .= 'Snippet: ' . $item->snippet . "\n\n";
                }
                Log::info($concat_results);
                $messages[] = ['role' => 'function','name' => 'web_search', 'content' => $concat_results];
                $response = OpenAI::chat()->create([
                    'model' => 'gpt-3.5-turbo-16k-0613',
                    'messages' => $messages,
                    "functions" => [
                        [
                            "name" => "web_search",
                            "description"=>  "A search engine. useful for when you need to gather new information, latest, recent, trending and upcoming. Also useful If you don't have information about the information asked.",
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
                            "name" => "web_scraper",
                            "description"=>  "A web scraper. useful for when you need to gather more information to answer a question if snippet of web search is not enough. This return the website text data excluding the styles, scripts and structure from the link.",
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

            }else if($response->choices[0]->message->functionCall->name=='web_scraper'){
                Log::info("web scrapping");
                $jsonData = json_decode($response->choices[0]->message->functionCall->arguments);
                $scrape_result = scrapeWebsiteAndReturnText($jsonData->url);
                Log::info($scrape_result);
                $messages[] = ['role' => 'function','name' => 'web_scraper', 'content' => $scrape_result];
                Log::info($response->choices[0]->message->functionCall->arguments);
                $response = OpenAI::chat()->create([
                    'model' => 'gpt-3.5-turbo-16k-0613',
                    'messages' => $messages,
                    "functions" => [
                        [
                            "name" => "web_search",
                            "description"=>  "A search engine. useful for when you need to gather new information, latest, recent, trending and upcoming. Also useful If you don't have information about the information asked.",
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
                            "name" => "web_scraper",
                            "description"=>  "A web scraper. useful for when you need to gather more information to answer a question if snippet of web search is not enough. This return the website text data excluding the styles, scripts and structure from the link.",
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
            if($response->choices[0]->message->content==null){
                $jsonData = json_decode($response->choices[0]->message->functionCall->arguments);
                Log::info($response->choices[0]->message->functionCall->arguments);
                if($response->choices[0]->message->functionCall->name=='get_current_weather'){
                    Log::info("Using weather api");
                    $location = $jsonData->location;
                    $url = "http://api.weatherapi.com/v1/current.json?key=0191ce76160f4b5b9ad31403230408&&aqi=no&q=" .urlencode($location);
                    $s_response = file_get_contents($url);
                    Log::info(json_encode($s_response));
                    $messages[] = ['role' => 'function','name' => 'get_current_weather', 'content' => $s_response];
                    $response = OpenAI::chat()->create([
                        'model' => 'gpt-3.5-turbo-16k-0613',
                        'messages' => $messages,
                        "functions" => [
                            [
                                "name" => "get_current_weather",
                                "description"=>  "Get the current weather in a given location. Returns Json data.",
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
                            ]
                        ],
                    ]);
                }else if($response->choices[0]->message->functionCall->name=='web_search'){
                    Log::info("Searching the web");
                    $apiKey = 'AIzaSyCNKAVmTCelLTeAxPGq_ShbIGfdv6WRaV4';
                    $cseID = '82a52554294294369';
                    $query = $jsonData->query;
                    $url = "https://www.googleapis.com/customsearch/v1?key=$apiKey&cx=$cseID&start=1&num=5&q=" .urlencode($query);
        
                    $s_response = file_get_contents($url);
                    $concat_results = "";
                    $results = json_decode($s_response);
                    foreach ($results->items as $item) {
                        $concat_results  .= 'Title: ' . $item->title . "\n";
                        $concat_results  .= 'Link: ' . $item->link . "\n";
                        $concat_results  .= 'Snippet: ' . $item->snippet . "\n\n";
                    }
                    Log::info($concat_results);
                    $messages[] = ['role' => 'function','name' => 'web_search', 'content' => $concat_results];
                    $response = OpenAI::chat()->create([
                        'model' => 'gpt-3.5-turbo-16k-0613',
                        'messages' => $messages,
                        "functions" => [
                            [
                                "name" => "web_search",
                                "description"=>  "A search engine. useful for when you need to gather new information, latest, recent, trending and upcoming. Also useful If you don't have information about the information asked.",
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
                            ]
                            ],
                            "functions" => [
                                [
                                    "name" => "web_scraper",
                                    "description"=>  "A web scraper. useful for when you need to gather more information to answer a question if snippet of web search is not enough. This return the website text data excluding the styles, scripts and structure from the link.",
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
    
                }else if($response->choices[0]->message->functionCall->name=='web_scraper'){
                    Log::info("web scrapping");
                    $jsonData = json_decode($response->choices[0]->message->functionCall->arguments);
                    $scrape_result = scrapeWebsiteAndReturnText($jsonData->url);
                    Log::info($scrape_result);
                    $messages[] = ['role' => 'function','name' => 'web_scraper', 'content' => $scrape_result];
                    Log::info($response->choices[0]->message->functionCall->arguments);
                    $response = OpenAI::chat()->create([
                        'model' => 'gpt-3.5-turbo-16k-0613',
                        'messages' => $messages,
                        "functions" => [
                            [
                                "name" => "web_search",
                                "description"=>  "A search engine. useful for when you need to gather new information, latest, recent, trending and upcoming. Also useful If you don't have information about the information asked.",
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
                                "name" => "web_scraper",
                                "description"=>  "A web scraper. useful for when you need to gather more information to answer a question if snippet of web search is not enough. This return the website text data excluding the styles, scripts and structure from the link.",
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

            }
            $messages[] = ['role' => 'assistant', 'content' => $response->choices[0]->message->content??"I can't find it anywhere on my web results."];
            $chat = Chat::updateOrCreate(
                [
                    'id' => $id,
                    'user_id' => Auth::id()
                ],
                [
                    'context' => $messages
                ]
            );
        }else{
            $messages[] = ['role' => 'assistant', 'content' => $response->choices[0]->message->content];
            $chat = Chat::updateOrCreate(
                [
                    'id' => $id,
                    'user_id' => Auth::id()
                ],
                [
                    'context' => $messages
                ]
            );
        }



        return redirect()->route('chat.show', [$chat->id]);
    }

}

function scrapeWebsiteAndReturnText($url) {
    // Create a new DOMDocument instance
    $dom = new \DOMDocument();

    // Load the HTML content from the URL

    Log::info("Scraping $url");
    $html = file_get_contents($url);
    Log::info("Parsing");

    // Suppress HTML errors (optional)
    libxml_use_internal_errors(true);

    // Load the HTML content into the DOMDocument
    $dom->loadHTML($html);

    // Create a DOMXPath object to navigate the DOMDocument
    $xpath = new \DOMXPath($dom);

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

    // Clean up whitespace and return the text content
    return trim($textContent);
}
