<?php namespace Todaymade\Daux\Format\HTML;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Todaymade\Daux\Config;
use Todaymade\Daux\Console\RunAction;
use Todaymade\Daux\ContentTypes\Markdown\ContentType;
use Todaymade\Daux\Daux;
use Todaymade\Daux\DauxHelper;
use Todaymade\Daux\Format\Base\LiveGenerator;
use Todaymade\Daux\GeneratorHelper;
use Todaymade\Daux\Tree\Content;
use Todaymade\Daux\Tree\Directory;
use Todaymade\Daux\Tree\Entry;
use Todaymade\Daux\Tree\Raw;

class Generator implements \Todaymade\Daux\Format\Base\Generator, LiveGenerator
{
    use RunAction;

    /** @var Daux */
    protected $daux;

    /**
     * @param Daux $daux
     */
    public function __construct(Daux $daux)
    {
        $this->daux = $daux;
    }

    /**
     * @return array
     */
    public function getContentTypes()
    {
        return [
            'markdown' => new ContentType($this->daux->getParams())
        ];
    }

    public function generateAll(InputInterface $input, OutputInterface $output, $width)
    {
        $destination = $input->getOption('destination');

        $params = $this->daux->getParams();
        if (is_null($destination)) {
            $destination = $this->daux->local_base . DIRECTORY_SEPARATOR . 'static';
        }

        $this->runAction(
            "Copying Static assets ...",
            $output,
            $width,
            function() use ($destination) {
                GeneratorHelper::copyAssets($destination, $this->daux->local_base);
            }
        );

        $output->writeLn("Generating ...");

        $text_search = ($input->getOption('text_search'));
        $params['text_search'] = $text_search;
        if ($text_search) {
            $index_pages = [];
        }
        $this->generateRecursive($this->daux->tree, $destination, $params, $output, $width, $index_pages);

        if ($text_search) {
            $tipuesearch_directory = $this->daux->local_base . DIRECTORY_SEPARATOR . 'tipuesearch' . DIRECTORY_SEPARATOR;
            file_put_contents($tipuesearch_directory . 'tipuesearch_content.json', json_encode(['pages' => $index_pages]));
            GeneratorHelper::copyRecursive ($tipuesearch_directory, $destination . DIRECTORY_SEPARATOR . 'tipuesearch');
        }

    }

    /**
     * Remove HTML tags, including invisible text such as style and
     * script code, and embedded objects.  Add line breaks around
     * block-level tags to prevent word joining after tag removal.
     * Also collapse whitespace to single space and trim result.
     * modified from: http://nadeausoftware.com/articles/2007/09/php_tip_how_strip_html_tags_web_page
     */
    private function strip_html_tags($text)
    {
        $text = preg_replace(
            array(
                // Remove invisible content
                '@<head[^>]*?>.*?</head>@siu',
                '@<style[^>]*?>.*?</style>@siu',
                '@<script[^>]*?.*?</script>@siu',
                '@<object[^>]*?.*?</object>@siu',
                '@<embed[^>]*?.*?</embed>@siu',
                '@<applet[^>]*?.*?</applet>@siu',
                '@<noframes[^>]*?.*?</noframes>@siu',
                '@<noscript[^>]*?.*?</noscript>@siu',
                '@<noembed[^>]*?.*?</noembed>@siu',
                // Add line breaks before and after blocks
                '@</?((address)|(blockquote)|(center)|(del))@iu',
                '@</?((div)|(h[1-9])|(ins)|(isindex)|(p)|(pre))@iu',
                '@</?((dir)|(dl)|(dt)|(dd)|(li)|(menu)|(ol)|(ul))@iu',
                '@</?((table)|(th)|(td)|(caption))@iu',
                '@</?((form)|(button)|(fieldset)|(legend)|(input))@iu',
                '@</?((label)|(select)|(optgroup)|(option)|(textarea))@iu',
                '@</?((frameset)|(frame)|(iframe))@iu',
            ),
            array(
                ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ',
                "\n\$0", "\n\$0", "\n\$0", "\n\$0", "\n\$0", "\n\$0",
                "\n\$0", "\n\$0",
            ),
            $text );
        return trim (preg_replace('/\s+/', ' ', strip_tags($text)));
    }

    /**
     * Recursively generate the documentation
     *
     * @param Directory $tree
     * @param string $output_dir
     * @param \Todaymade\Daux\Config $params
     * @param OutputInterface $output
     * @param integer $width
     * @param string $base_url
     * @throws \Exception
     */
    private function generateRecursive(Directory $tree, $output_dir, $params, $output, $width, &$index_pages, $base_url = '')
    {
        DauxHelper::rebaseConfiguration($params, $base_url);

        if ($base_url !== '' && empty($params['entry_page'])) {
            $params['entry_page'] = $tree->getFirstPage();
        }

        foreach ($tree->getEntries() as $key => $node) {
            if ($node instanceof Directory) {
                $new_output_dir = $output_dir . DIRECTORY_SEPARATOR . $key;
                mkdir($new_output_dir);
                $this->generateRecursive($node, $new_output_dir, $params, $output, $width, $index_pages, '../' . $base_url);

                // Rebase configuration again as $params is a shared object
                DauxHelper::rebaseConfiguration($params, $base_url);
            } else {
                $this->runAction(
                    "- " . $node->getUrl(),
                    $output,
                    $width,
                    function() use ($node, $output_dir, $key, $params, &$index_pages) {
                        if (!$node instanceof Content) {
                            copy($node->getPath(), $output_dir . DIRECTORY_SEPARATOR . $key);
                            return;
                        }

                        $generated = $this->generateOne($node, $params);
                        file_put_contents($output_dir . DIRECTORY_SEPARATOR . $key, $generated->getContent());
                        if (isset($index_pages)) {
                            array_push($index_pages, [
                                'title' => $node->getTitle(),
                                'text' => utf8_encode($this->strip_html_tags($generated->getContent())),
                                'tags' =>  "",
                                'url' => $node->getUrl()
                            ]);
                        }
                    }
                );
            }
        }
    }

    /**
     * @param Entry $node
     * @param Config $params
     * @return \Todaymade\Daux\Format\Base\Page
     */
    public function generateOne(Entry $node, Config $params)
    {
        if ($node instanceof Raw) {
            return new RawPage($node->getPath());
        }

        $params['request'] = $node->getUrl();
        return ContentPage::fromFile($node, $params, $this->daux->getContentTypeHandler()->getType($node));
    }
}
