<?php

namespace App\Http\Controllers;

use App\Models\Outline;
use App\Services\OllamaService;
use Illuminate\Http\Request;

class OutlineController extends Controller
{
    protected OllamaService $service;

    public function __construct(OllamaService $service)
    {
        $this->service = $service;
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required',
            'topic' => 'required',
            'tone' => 'required',
        ]);

        $outline = new Outline();
        $outline->title = $request->title;
        $outline->topic = $request->topic;
        $outline->tone = $request->tone;
        $outline->save();

        return redirect('/')->with('outline', $outline);
    }

    public function generate(Request $request, $id)
    {
        $outline = Outline::findOrFail($id);
        return response()->stream(
            function () use (
                $outline
            ) {
                $result_text = "";
                $messages = $outline->getPromptMessages();
                $stream = $this->service->ask($messages);

                foreach ($stream as $response) {
                    if ($response->done) {
                        break;
                    }

                    $text = $response->message->content;
                    if (connection_aborted()) {
                        break;
                    }
                    $data = [
                        'text' => $text,
                    ];
                    $this->send("update", json_encode($data));
                    $result_text .= $text;
                }

                $this->send("update", "<END_STREAMING_SSE>");
                $outline->content = $result_text;
                $outline->save();
            },
            200,
            [
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
                'Content-Type' => 'text/event-stream',
            ]
        );
    }

    private function send($event, $data)
    {
        echo "event: {$event}\n";
        echo 'data: ' . $data;
        echo "\n\n";
        ob_flush();
        flush();
    }

}
