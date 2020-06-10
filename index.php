<?php
interface IOutputFormatter {
    public function toString(Quiz $quiz);
}
abstract class OutputFormatter implements IOutputFormatter
{
    public $Quiz;
    abstract public function toString(Quiz $quiz);
}
class JsonOutputFormatter extends OutputFormatter implements IOutputFormatter
{
    public function toString(Quiz $quiz)
    {
        $this->Quiz = $quiz;
        $details = $this->Quiz->getDetails();
        $questions = $this->Quiz->getQuestions();
        $json = new stdClass();
        $json->Details = $details;
        $json->Questions = $questions;
        header('Content-Type: application/json');
        return json_encode($json);
    }
}
class DieckwischOutputFormatter extends OutputFormatter implements IOutputFormatter
{
    public function toString(Quiz $quiz)
    {
        $return = '';
        $this->Quiz = $quiz;
        $details = $this->Quiz->getDetails();
        $return .= sprintf('<h1>Details for Catalog %s</h1>', $details["OriginalFileName"]);
        $questions = $this->Quiz->getQuestions();
        $spacer = '--!';
        $answerTemplate = array(true => '#!', false => '#?');
        foreach ($questions as $question) :
            if ($question->Number !== 1) : // there must not be a --! for the first question.
                $return .= sprintf($spacer."\r\n");
            endif;
            $return .= sprintf("%d %s\r\n", $question->Number, $question->Text);
            foreach ($question->Answers as $answer) :
                $return .= sprintf("%s %s\r\n", $answerTemplate[$answer->IsCorrect], $answer->Text);
            endforeach;
            if (!empty($question->Attachments)) :
                $attachment = $question->Attachments->Attachment[0];
                $attachmentString = sprintf('<img src="data:image/jpg;base64%s" alt="Anlage %d" title="Anlage %d"/>'."\r\n", $attachment->Content, $question->Attachments->number, $question->Attachments->number);
                $return .= $attachmentString;
                // $return .= htmlentities($attachmentString); // add it again in case we want to copy it as source (too bloated)
            endif;
        endforeach;
        return $return;
    }
}

class XObjectFilter
{
    /**
    * remove duplicate attachments - most images are stored twice in the PDF
    *
    * @param array $attachments
    */
    public static function reduceDuplicateAttachments(array $attachments)
    {
        $temp = array_unique(array_column($attachments, 'Content'));
        return array_intersect_key($attachments, $temp);
    }
    /**
    * Extension method to retrieve objects by type from an individual page object
    *
    * @param mixed $XObjects
    * @param mixed $type
    * @param mixed $subtype
    */
    public static function getObjectsByType($XObjects, $type, $subtype = null)
    {
        $objects = [];
        foreach ($XObjects as $id => $object) {
            if ($object->getHeader()->get('Type') == $type &&
                (null === $subtype || $object->getHeader()->get('Subtype') == $subtype)
            ) {
                $objects[$id] = $object;
            }
        }
        return $objects;
    }
}
class TextCleaner
{
    const Whitespace = array("\t", "\n", "\r\n");
    public static function Clean($input)
    {
        return str_replace(self::Whitespace, '', trim($input));
    }
}
class Attachment {
    public $Details = array();
    public $Content = '';

    public function __construct($details, $content)
    {
        $this->Details = $details;
        $this->Content = base64_encode($content);
    }
    public function __toString()
    {
        return sprintf('<img src="data:image/jpg;base64,%s"></img><!--%s-->', $this->Content, print_r($this->Details, true));
    }
    public static function createFromXObject($XObject)
    {
        $imageInfo = $XObject->getDetails();
        if ($imageInfo['Filter'] !== 'DCTDecode') :
            return new self();
        endif;
        return new self($imageInfo, $XObject->getContent());
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
        if (!is_null($attachments)) :
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
    private $OutputFormatter = null;
    private $Questions = array();
    private $Details = array();
    private $Attachments = array();
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
    private $regexAttachments = '#Anlage\s+(\d{1,2})#i'; //Anlagen zu den Aufgaben\t \t  \nv20\t20.2 \t \t1 \t\nAnlage 1\t \t\n
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
    private function getAttachmentPattern()
    {
        return $this->regexAttachments;
    }

    public function __construct($sourceFile, $parser, $skipPages = null)
    {
        $this->sourceFile = $sourceFile;
        $this->parser = $parser;
        if (is_numeric($skipPages) && (int) $skipPages > 0) :
            $this->skipPages = $skipPages;
        endif;
        $this->parsePdf();
        $this->Details['OriginalFileName'] = $sourceFile;
        $this->OutputFormatter = new JsonOutputFormatter(); // default formatter
    }
    public function setOutputFormatter(IOutputFormatter $outputFormatter)
    {
        $this->OutputFormatter = $outputFormatter;
    }
    public function getDetails()
    {
        return $this->Details;
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
        // capture a "Siehe Anlage 1" that follows a question. If that attachment exists, add it's contents. Formatter (JSON etc) should pick an output format
        elseif (is_numeric(($attachment = filter_var($answerIntro, FILTER_SANITIZE_NUMBER_INT)))) :
            $return['attachments'] = new stdClass();
            $return['attachments']->number = (int) $attachment;
            $return['attachments']->Attachment = null;
            if (array_key_exists($attachment, $this->Attachments)) :
                $return['attachments']->Attachment = $this->Attachments[$attachment];
            endif;
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
    private function parseAttachments($pdfPage)
    {
        $attachments = array();
        $XObjects = $pdfPage->getXObjects();
        $XObjects = XObjectFilter::getObjectsByType($XObjects, 'XObject', 'Image');
        if (empty($XObjects)) :
            return;
        endif;
        foreach ($XObjects as $XObject) :
            $attachments[] = Attachment::createFromXObject($XObject);
        endforeach;
        return XObjectFilter::reduceDuplicateAttachments($attachments);
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
        $skipAttachmentPage = false; // see edge case empty attachment page below
        $pdf = $this->parser->parseFile($this->sourceFile);
        $this->Details = $pdf->getDetails();
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
            if (preg_match($this->getIntroPattern(), $s, $matchesIntro)) :
                $s = str_replace($matchesIntro[1], '', $s); // substr might be simpler maybe. Do we trust the length? Guess this will work.
            elseif (preg_match($this->getAttachmentPattern(), $s, $matchesAttachments)) :
                if (!is_numeric(($attachmentNumber = filter_var($matchesAttachments[1], FILTER_SANITIZE_NUMBER_INT)))) :
                    trigger_error("Unable to extract attachment number from $s", E_USER_NOTICE);
                endif;
                $attachmentNumber = (int) $attachmentNumber;
                // edge case in Nav; actual attachment may be on a page after the intro (e.g. Att Page 3 = Anlage 3 + blank page, Page 4 is the image)
                $attachment = $this->parseAttachments($page);
                if (empty($attachment)) :
                    $skipAttachmentPage = true;
                    continue;
                endif;
                $this->Attachments[$attachmentNumber] = $attachment;
                // in theory, all further pages should be attachments. What do we do?
            elseif ($skipAttachmentPage) :  // see edge case nav chart "Anlage 3")
                $skipAttachmentPage = false;
                $attachment = $this->parseAttachments($page);
                $this->Attachments[$attachmentNumber] = $attachment;
            endif;
            $fullText .= $s;
        endforeach;
        $this->fullText = $fullText;
    }

    public function __toString()
    {
        return $this->OutputFormatter->toString($this);
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
    public function __construct($sourceDirectory, \Smalot\PdfParser\Parser $pdfParser)
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
                $this->quizes[] = $quiz;
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
//$quiz = new Quiz('./pdf/PPL(A)/ECQB-PPL-40-COM-PPLA-DE.pdf', $parser);
$quiz = new Quiz('./pdf/PPL(A)/ECQB-PPL-90-NAV-PPLA-DE-DE.pdf', $parser);
header('Content-Type: application/json');
echo $quiz->getJson();
die();
*/

$quizParser = new quizParser('./pdf/PPL(A)/', $parser);
$quizes = $quizParser->parseDirectory();
$outputFormatter = new DieckwischOutputFormatter();
foreach ($quizes as $quiz) :
    $quiz->setOutputFormatter($outputFormatter);
    echo $quiz;
endforeach;
?>