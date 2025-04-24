<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\VolcengineService;
use Illuminate\Support\Facades\Log;

class DeepSeekController extends Controller
{
    protected $volcengineService;

    public function __construct(VolcengineService $volcengineService)
    {
        $this->volcengineService = $volcengineService;
    }

    /**
     * 处理AI内容创作请求
     * 
     * 根据用户提供的参数构建提示词，调用VolcengineService生成内容
     * 
     * 推荐前端使用 multipart/form-data 方式提交参数，尤其是 text 字段很长时。
     * text 字段应作为 form-data 的普通字段提交（不是文件）。
     * 不建议使用 query string 或 application/x-www-form-urlencoded 方式传递大文本。
     * 
     * @param Request $request 包含创作所需的所有参数
     * @return \Illuminate\Http\JsonResponse 返回AI生成的内容
     * @throws \Exception 当API调用失败时抛出异常
     */
    public function chat(Request $request)
    {
        // 记录请求开始
        Log::info('DeepSeekController::chat - 请求开始', [
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent')
        ]);
        
        $request->validate([
            // Model ID
            'model_id' => 'required|string',
            // 文本（文章内容作为基础）
            'text' => 'required|string',
            // 推广渠道 
            'channels' => 'required|string',
            // 内容方向
            'direction' =>'required|string',
            // 说明要求
            'requirements' =>'required|string',
            // 创作篇数
            'num' =>'required|integer',
            // SEO关键词
            "seo_keywords" =>"required|string",
            // 内容作用
            "scope" =>"required|string",
        ]);

        // 文本预处理 - 如果超过一定长度则进行简要总结
        $originalText = $request->text;
        $processedText = $this->preprocessText($originalText);
        
        // 构建结构化提示词模板
        $promptTemplate = <<<EOT
            # 文章创作任务

            ## 输入材料
            以下是原始文章内容，请仔细阅读并理解:
            ```
            {text}
            ```

            ## 任务详情
            1. 基于上述输入材料，创作{num}篇高质量文章
            2. 每篇文章需要满足以下要求:
            - 重点针对"{channels}"渠道进行优化
            - 主题围绕"{direction}"展开
            - 需要遵循"{requirements}"的内容要求
            - 字数要求: 不少于原文字数，但控制在合理范围内
            - 每篇文章都需要有吸引人的标题
            - 内容结构清晰，段落分明，易于阅读
            3. 在文章末尾输出SEO关键词，并给出关键词密度等优化建议。
            4. 如果文章内有年份月份等，请用HTML实体替换数字。

            ## SEO优化要求
            请在文章中自然融入以下关键词: {seo_keywords}

            ## 文章用途
            文章将用于: {scope}

            ## 输出格式
            请使用Markdown格式输出文章，每篇文章之间用"---"分隔。
            EOT;
        
        // 替换模板中的变量
        $prompt = str_replace(
            ['{text}', '{channels}', '{direction}', '{requirements}', '{num}', '{seo_keywords}', '{scope}'],
            [$processedText, $request->channels, $request->direction, $request->requirements, $request->num, $request->seo_keywords, $request->scope],
            $promptTemplate
        );
        
        try {
            $response = $this->volcengineService->getReply($prompt, [], $request->model_id);
            
            return response()->json([
                'success' => true,
                'data' => $response
            ]);
        } catch (\Exception $e) {
            // 记录API调用失败
            Log::error('DeepSeekController::chat - API调用失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 预处理文本内容
     * 
     * 对特别长的文本进行处理，确保不超过模型处理能力
     * 
     * @param string $text 原始文本
     * @return string 处理后的文本
     */
    private function preprocessText(string $text): string
    {
        // 估计文本字符数，一般中文模型的上下文窗口为4000-8000字之间
        // 这里取一个保守值，留出足够空间给其他参数和模型回复
        $maxTextLength = 4000;
        
        if (mb_strlen($text) <= $maxTextLength) {
            return $text;
        }
        
        // 如果文本超长，截取前一部分并添加处理说明
        $truncatedText = mb_substr($text, 0, $maxTextLength);
        $truncatedText .= "\n\n[注: 由于原文较长，这里截取了前{$maxTextLength}字。请基于这部分内容进行创作，同时保持文章完整性。]";
        
        return $truncatedText;
    }

    /**
     * 处理AI内容流式创作请求
     * 
     * 根据用户提供的参数构建提示词，调用VolcengineService生成内容并以流式方式返回
     * 
     * 推荐前端使用 EventSource 或同等技术接收流式响应
     * 
     * @param Request $request 包含创作所需的所有参数
     * @return \Symfony\Component\HttpFoundation\StreamedResponse 返回流式响应
     * @throws \Exception 当API调用失败时抛出异常
     */
    public function streamChat(Request $request)
    {
        // 记录请求开始
        Log::info('DeepSeekController::streamChat - 流式请求开始', [
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent')
        ]);
        
        $request->validate([
            // 与 chat 方法相同的验证规则
            'model_id' => 'required|string',
            'text' => 'required|string',
            'channels' => 'required|string',
            'direction' =>'required|string',
            'requirements' =>'required|string',
            'num' =>'required|integer',
            "seo_keywords" =>"required|string",
            "scope" =>"required|string",
        ]);
        
        // 文本预处理 - 如果超过一定长度则进行简要总结
        $originalText = $request->text;
        $processedText = $this->preprocessText($originalText);
        
        // 构建结构化提示词模板
        $promptTemplate = <<<EOT
            # 文章创作任务

            ## 输入材料
            以下是原始文章内容，请仔细阅读并理解:
            ```
            {text}
            ```

            ## 任务详情
            1. 基于上述输入材料，创作{num}篇高质量文章
            2. 每篇文章需要满足以下要求:
            - 重点针对"{channels}"渠道进行优化
            - 主题围绕"{direction}"展开
            - 需要遵循"{requirements}"的内容要求
            - 字数要求: 和原文字数控制在90-110%，但控制在合理范围内
            - 每篇文章都需要有吸引人的标题
            - 内容结构清晰，段落分明，易于阅读
            3. 在文章末尾输出SEO关键词，并给出关键词密度等优化建议。

            ## SEO优化要求
            请在文章中自然融入以下关键词: {seo_keywords}

            ## 文章用途
            文章将用于: {scope}

            ## 输出格式
            请使用docx格式输出文章，不要有任何markdown格式的标签，每篇文章之间用"---"分隔。
            EOT;
        
        $prompt = str_replace(
            ['{text}', '{channels}', '{direction}', '{requirements}', '{num}', '{seo_keywords}', '{scope}'],
            [$processedText, $request->channels, $request->direction, $request->requirements, $request->num, $request->seo_keywords, $request->scope],
            $promptTemplate
        );
        
        // 创建流式响应
        return response()->stream(function () use ($prompt, $request) {
            try {
                // 设置SSE头部
                echo "Content-Type: text/event-stream\n";
                echo "Cache-Control: no-cache\n";
                echo "Connection: keep-alive\n\n";
                
                // 发送初始消息
                echo "data: " . json_encode(['type' => 'start']) . "\n\n";
                ob_flush();
                flush();
                
                // 使用流式API获取响应
                foreach ($this->volcengineService->streamChat($prompt, [], $request->model_id) as $chunk) {
                    if (!empty($chunk)) {
                        // 发送数据块
                        echo "data: " . json_encode([
                            'type' => 'chunk',
                            'content' => $chunk
                        ]) . "\n\n";
                        ob_flush();
                        flush();
                    }
                }
                
                // 发送完成消息
                echo "data: " . json_encode(['type' => 'end']) . "\n\n";
                ob_flush();
                flush();
                
                // 记录成功完成流式传输
                Log::info('DeepSeekController::streamChat - 流式传输完成');
            } catch (\Exception $e) {
                // 记录错误
                Log::error('DeepSeekController::streamChat - 流式传输失败', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // 发送错误消息
                echo "data: " . json_encode([
                    'type' => 'error',
                    'message' => $e->getMessage()
                ]) . "\n\n";
                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // 禁用Nginx缓冲
        ]);
    }
}
