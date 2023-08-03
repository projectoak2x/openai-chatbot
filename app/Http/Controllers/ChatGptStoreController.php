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
        $messages[] = ['role' => 'system', 'content' => 'use this date as today ' . date("Y-m-d")];
        if ($id) {
            $chat = Chat::findOrFail($id);
            $messages = $chat->context;
        }
        $messages[] = ['role' => 'user', 'content' => $request->input('promt')];
        $response = OpenAI::chat()->create([
            'model' => 'gpt-4-0613',
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
                ]
                ],
        ]);
        if($response->choices[0]->message->content==null){
            Log::info("Searching the web");
            Log::info($response->choices[0]->message->functionCall->arguments);
            $jsonData = json_decode($response->choices[0]->message->functionCall->arguments);
            $apiKey = 'AIzaSyBDqhWn68F6IWEAo4i8oOHbG4TBF0NHtaY';
            $cseID = 'd78ab3d3598cf48e2';
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
                'model' => 'gpt-4-0613',
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
            ]);
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
