<?php
namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;

class VolcengineService
{
    private $client;

    public function __construct()
    {
        $this->client = new Client();
        // 使用火山引擎接口
        $this->baseUrl = config('services.volcengine.base_url');
        $this->apiKey = config('services.volcengine.api_key');
    }

    /**
     * 调用火山引擎对话API
     *
     * @param string $prompt 用户输入的提示词
     * @param array $messages 历史消息数组
     * @param string|null $modelId 模型ID，默认使用配置中的deepseek-v3
     * @param float $temperature 温度参数，控制随机性，默认0.7
     * @param float $topP 控制输出多样性的参数，默认0.8
     * @param bool $stream 是否使用流式响应，默认false
     * @return array 返回API响应结果
     * @throws \Exception 当API调用失败时抛出异常
     */
    public function chat(string $prompt, array $messages = [], ?string $modelId = null, float $temperature = 0.7, float $topP = 0.8, bool $stream = false): array
    {
        try {
            // 如果没有历史消息，则创建一个只包含当前提示的消息数组
            if (empty($messages)) {
                $messages = [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ];
            } else {
                // 添加当前提示到消息数组
                $messages[] = [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ];
            }

            // 如果未指定模型ID，则使用配置中的默认模型
            if ($modelId === null) {
                $modelId = config('model_id.deepseek-v3');
            }

            // 构建请求体
            $requestBody = [
                'model' => $modelId,
                'messages' => $messages,
                'temperature' => $temperature,
                'top_p' => $topP,
                'stream' => $stream
            ];

            // 发送请求到火山引擎API
            $response = $this->client->post($this->baseUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json'
                ],
                'json' => $requestBody
            ]);

            // 解析响应
            $result = json_decode($response->getBody()->getContents(), true);
            
            return $result;
        } catch (ClientException $e) {
            // 处理API错误
            $errorResponse = json_decode($e->getResponse()->getBody()->getContents(), true);
            Log::error('DeepSeekService::chat - API调用失败', [
                'error' => $errorResponse,
                'message' => $e->getMessage()
            ]);
            throw new \Exception('火山引擎API调用失败: ' . ($errorResponse['error']['message'] ?? $e->getMessage()));
        } catch (\Exception $e) {
            // 处理其他异常
            Log::error('DeepSeekService::chat - 服务调用失败', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception('调用火山引擎服务失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取模型回复内容
     * 
     * @param string $prompt 用户输入的提示词
     * @param array $messages 历史消息数组
     * @param string|null $modelId 模型ID
     * @return string 返回模型回复的文本内容
     * @throws \Exception 当API调用失败时抛出异常
     */
    public function getReply(string $prompt, array $messages = [], ?string $modelId = null): string
    {
        
        $result = $this->chat($prompt, $messages, $modelId);
        
        // 检查响应中是否包含有效的回复
        if (isset($result['choices'][0]['message']['content'])) {
            $content = $result['choices'][0]['message']['content'];
            
            // 记录返回结果
            Log::info('DeepSeekService::getReply - 返回结果', [
                'content_length' => strlen($content)
            ]);
            
            return $content;
        }
        
        Log::error('DeepSeekService::getReply - 无法获取模型回复内容', [
            'result' => json_encode($result, JSON_UNESCAPED_UNICODE)
        ]);
        
        throw new \Exception('无法获取模型回复内容');
    }

    /**
     * 调用火山引擎对话API（流式响应）
     *
     * @param string $prompt 用户输入的提示词
     * @param array $messages 历史消息数组
     * @param string|null $modelId 模型ID，默认使用配置中的deepseek-v3
     * @param float $temperature 温度参数，控制随机性，默认0.7
     * @param float $topP 控制输出多样性的参数，默认0.8
     * @return \Generator 返回生成器，逐步产出响应数据
     * @throws \Exception 当API调用失败时抛出异常
     */
    public function streamChat(string $prompt, array $messages = [], ?string $modelId = null, float $temperature = 0.7, float $topP = 0.8): \Generator
    {
        try {
            // 如果没有历史消息，则创建一个只包含当前提示的消息数组
            if (empty($messages)) {
                $messages = [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ];
            } else {
                // 添加当前提示到消息数组
                $messages[] = [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ];
            }

            // 如果未指定模型ID，则使用配置中的默认模型
            if ($modelId === null) {
                $modelId = config('model_id.deepseek-v3');
            }

            // 构建请求体
            $requestBody = [
                'model' => $modelId,
                'messages' => $messages,
                'temperature' => $temperature,
                'top_p' => $topP,
                'stream' => true  // 设置为流式响应
            ];

            // 发送流式请求到火山引擎API
            $response = $this->client->post($this->baseUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json'
                ],
                'json' => $requestBody,
                'stream' => true, // 启用 Guzzle 流式传输
                'decode_content' => true
            ]);

            // 逐行处理流式响应
            $buffer = '';
            $stream = $response->getBody();
            
            // 用于累积内容的缓冲区
            $contentBuffer = '';
            // 缓冲区大小阈值，超过此值将强制输出
            $bufferThreshold = 50;
            // 定义可能表示完整语义单元结束的字符
            $semanticBreakChars = [' ', ',', '.', '!', '?', '，', '。', '！', '？', '、', '；', '：', "\n", "\r"];
            
            while (!$stream->eof()) {
                // 读取一行数据
                $line = $stream->read(1024);
                $buffer .= $line;
                
                // 处理完整的数据行
                $lines = explode("\n", $buffer);
                
                // 最后一行可能不完整，保留到下一次迭代
                $buffer = array_pop($lines);
                
                foreach ($lines as $line) {
                    // 跳过空行
                    if (empty(trim($line))) {
                        continue;
                    }
                    
                    // 跳过SSE规范中的冒号前缀行
                    if (strpos(trim($line), 'data: ') === 0) {
                        $line = substr(trim($line), 6); // 移除 "data: " 前缀
                    }
                    
                    // 处理特殊的结束标记，对应于流结束
                    if (trim($line) === '[DONE]') {
                        // 输出剩余的缓冲内容
                        if (!empty($contentBuffer)) {
                            yield $contentBuffer;
                        }
                        yield '';
                        return;
                    }
                    
                    try {
                        // 解析JSON数据
                        $data = json_decode($line, true);
                        
                        // 检查是否有内容
                        if (isset($data['choices'][0]['delta']['content'])) {
                            $content = $data['choices'][0]['delta']['content'];
                            
                            // 将新内容添加到缓冲区
                            $contentBuffer .= $content;
                            
                            // 检查是否应该输出缓冲区内容
                            $shouldYield = false;
                            
                            // 如果缓冲区达到阈值大小，则输出
                            if (mb_strlen($contentBuffer) >= $bufferThreshold) {
                                $shouldYield = true;
                            } 
                            // 如果内容以语义分隔符结尾，则输出
                            elseif (!empty($contentBuffer)) {
                                $lastChar = mb_substr($contentBuffer, -1);
                                if (in_array($lastChar, $semanticBreakChars)) {
                                    $shouldYield = true;
                                }
                            }
                            
                            // 如果应该输出，则yield缓冲区内容并清空缓冲区
                            if ($shouldYield && !empty($contentBuffer)) {
                                yield $contentBuffer;
                                $contentBuffer = '';
                            }
                        }
                    } catch (\Exception $e) {
                        // 忽略无效的JSON
                        Log::warning('DeepSeekService::streamChat - 无效的响应行', [
                            'line' => $line,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
            
            // 确保缓冲区中的最后内容被输出
            if (!empty($contentBuffer)) {
                yield $contentBuffer;
            }
        } catch (ClientException $e) {
            // 处理API错误
            $errorResponse = json_decode($e->getResponse()->getBody()->getContents(), true);
            Log::error('DeepSeekService::streamChat - API调用失败', [
                'error' => $errorResponse,
                'message' => $e->getMessage()
            ]);
            throw new \Exception('火山引擎API调用失败: ' . ($errorResponse['error']['message'] ?? $e->getMessage()));
        } catch (\Exception $e) {
            // 处理其他异常
            Log::error('DeepSeekService::streamChat - 服务调用失败', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception('调用火山引擎服务失败: ' . $e->getMessage());
        }
    }
}