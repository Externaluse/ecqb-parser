<?php
class TextCleaner
{
    const Whitespace = array("\t", "\n", "\r\n");
    public static function Clean($input)
    {
        return str_replace(self::Whitespace, '', trim($input));
    }
}

class Answer
{
    public $Text = '';
    public $Number = 0;
    public $IsCorrect = false;
    public function __construct($number, $text, $isCorrect)
    {
        $this->Number = $number;
        $this->Text = TextCleaner::Clean($text);
        $this->IsCorrect = $isCorrect;
        return $this;
    }
}
class Question
{
    public $Number = 0;
    public $Text = '';
    public $Answers = array();
    public $Attachments = array();

    public function __construct($number, $text, array $answers, $attachments = null)
    {
        $this->Number = $number;
        $this->Text = TextCleaner::Clean($text);
        $this->Answers = $answers;
        if (!is_null($attachments) && is_array($attachments)) :
            $this->Attachments = $attachments;
        endif;
        return $this;
    }

    public function getText()
    {
        return $this->Text;
    }
    public function getNumber()
    {
        return $this->Number;
    }
}

class Quiz
{
    private $Questions = array();
    private $sourceFile = '';
    private $parser = null;
    private $fullText = '';
    private $regexOptions = 'mis';
    private $regex = array(
        '\\n\s*([1-9]{1}\d{0,2})\s*(?=\\t+)\s*', // \n1 \t...(Die (gedachte) Erdachse)
        '(.+?)', // The actual question (non-greedy anything until next pattern match
        // '\(\d+[.,]\d+\s*P\.\)'; //matches (1,00 P.) points score
        '(P\.\))', //matches P.) points score
    );
    private $regexIntro = '^(.+?)';
    private $skipPages = 2; // how many pages to skip; most have two
    private $numberOfQuestions = 0;

    private function getPattern()
    {
        return sprintf('#%s#%s', join('', $this->regex), $this->regexOptions);
    }
    private function getIntroPattern()
    {
        return sprintf('#%s%s#%s', $this->regexIntro, join('', $this->regex), $this->regexOptions);
    }

    public function __construct($sourceFile, $parser, $skipPages = null)
    {
        $this->sourceFile = $sourceFile;
        $this->parser = $parser;
        if (is_numeric($skipPages) && (int) $skipPages > 0) :
            $this->skipPages = $skipPages;
        endif;
        $this->parsePdf();
    }
    public function getQuestions()
    {
        $this->parseQuestions();
        return $this->Questions;
    }

    private function parseAnswers($input)
    {
        $return = array('answers' => array(), 'attachments' => null)   ;
        $answers = preg_split("#(\u{f0a8}|\u{f0fe})#umi", $input, -1, PREG_SPLIT_DELIM_CAPTURE);
        $answerIntro = trim($answers[0]);
        if ($answerIntro === '') :
            unset($answers[0]);
        // capture a "Siehe Anlage 1" that follows a question
        elseif (is_numeric(($attachment = filter_var($answerIntro, FILTER_SANITIZE_NUMBER_INT)))) :
            $return['attachments'] = (int) $attachment;
            unset($answers[0]);
        else :
            throw new Exception("Unrecognised answer intro $answerIntro");
        endif;
        $numberOfAnswers = count($answers);
        if ($numberOfAnswers !== 8) :
            throw new Exception("Unexpected number of answer splits $numberOfAnswers !== 8");
        endif;
        $answerChunks = array_chunk($answers, 2);
        $i = 0;
        foreach ($answerChunks as $answer) :
            ++$i;
            $isCorrect = false;
            // incorrect: \u{f0a8}; correct \u{f0fe}
            //if (preg_match("#\u{f0fe}#ui", $answer[0])) :
            if ($answer[0] === "\u{f0fe}") : // less costly
                $isCorrect = true;
            endif;
            $return['answers'][] = new Answer($i, $answer[1], $isCorrect);
        endforeach;
        return $return;
    }

    private function parseQuestions()
    {
        // It's expensive but gives us something to check against
        $pattern = $this->getPattern();
        $this->numberOfQuestions = preg_match_all($pattern, $this->fullText, $matches, PREG_SET_ORDER, 0);
        $split = preg_split($pattern, $this->fullText, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $splitCount = count($split);
        // warn if $split !== $numberOfQuestions * 4
        if ($this->numberOfQuestions * 4 !== $splitCount) :
            throw new Exception("Unexpected number of text splits $this->numberOfQuestions * 4 !== $splitCount");
        endif;
        // let's chunk into questions:
        $chunks = array_chunk($split, 4);
        foreach ($chunks as $question) :
            $questionNumber = (int) $question[0];
            $questionText = $question[1].$question[2];
            try {
                $answers = $this->parseAnswers($question[3]);
            } catch (Exception $ex) {
                throw new Exception("Error parsing answers in question $questionNumber", null, $ex->getMessage());
            }

            //$this->Questions[$questionNumber] = new Question();
            $this->Questions[$questionNumber] = new Question($questionNumber, $questionText, $answers['answers'], $answers['attachments']);
        endforeach;
        return $this->Questions;
    }
    /**
    * Parses PDF into $this->fullText
    *
    */
    private function parsePdf()
    {
        $pdf = $this->parser->parseFile($this->sourceFile);
        $pages  = $pdf->getPages();
        $i = 0;
        $fullText = '';
        foreach ($pages as $page) :
            ++$i;
            if ($i <= $this->skipPages) :
                continue;
            endif;
            $s = $page->getText();
            // cut the header, e.g.  \t90 – Navigation\t \tECQB\t-PPL(A)\t \t\nv20\t20.2 \t \t3 \t
            if (preg_match($this->getIntroPattern(), $s, $matchesIntro)) {
                $s = str_replace($matchesIntro[1], '', $s); // substr might be simpler maybe. Do we trust the length? Guess this will work.
            }
            $fullText .= $s;
        endforeach;
        $this->fullText = $fullText;
    }

    public function getJson()
    {
        $return = $this->parseQuestions();
        return json_encode($return);
    }

    public function getFullText()
    {
        return $this->fullText;
    }
}

class quizParser
{
    private $sourceDirectory = '';
    private $pdfParser = null;
    private $quizes = array();
    public function __construct($sourceDirectory, $pdfParser)
    {
        $this->sourceDirectory = $sourceDirectory;
        $this->pdfParser = $pdfParser;
    }

    public function parseDirectory()
    {
        $directory = new DirectoryIterator($this->sourceDirectory);
        foreach ($directory as $file) {
            if ($file->getType() !==  'file' || $file->getExtension() !== 'pdf') : // eergh, magic strings. Where's that constant?
                continue;
            endif;
            $quiz = new Quiz($file->getPathname(), $this->pdfParser);
            try {
                $this->quizes[] = $quiz->getQuestions();
            } catch (Exception $ex) {
                trigger_error("Caught exception: ".$ex->getMessage()." in ".$file->getFilename(), E_USER_NOTICE);
            }

        }
        return $this->quizes;
    }

}

error_reporting(E_ALL & ~E_NOTICE & ~E_USER_NOTICE);
// Include Composer autoloader if not already done.
include 'vendor/autoload.php';
// Parse pdf file and build necessary objects.
$parser = new \Smalot\PdfParser\Parser();

/*
$quiz = new Quiz('./pdf/PPL(A)/ECQB-PPL-40-COM-PPLA-DE.pdf', $parser);
header('Content-Type: application/json');
echo $quiz->getJson();
die();
*/

$quizParser = new quizParser('./pdf/PPL(A)/', $parser);
$quizes = $quizParser->parseDirectory();
header('Content-Type: application/json');
echo json_encode($quizes);
die();
$quiz = new Quiz('./pdf/PPL(A)/ECQB-PPL-90-NAV-PPLA-DE-DE.pdf', $parser);
header('Content-Type: application/json');
echo $quiz->getJson();
?>