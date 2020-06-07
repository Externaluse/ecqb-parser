<?php
  die();
// Parse pdf file and build necessary objects.
$parser = new \Smalot\PdfParser\Parser();
$pdf    = $parser->parseFile('./pdf/PPL(A)/ECQB-PPL-90-NAV-PPLA-DE-DE.pdf');
$regexOptions = 'mis'; // global; multiline, case insensitive (g not used in preg_match_all), AND bloody "s" that cost me an hour to match newlines
$introOptions = 'is';
$regex = array();
$regex[] = '\\n\s*(\d+)\s*\\t*'; // \n1 \t...(Die (gedachte) Erdachse)
$regex[] = '(.+?)'; // The actual question (non-greedy anything until next pattern match
//$regex[] = '\(\d+[.,]\d+\s*P\.\)'; //matches (1,00 P.) points score
$regex[] = '(P\.\))'; //matches P.) points score
$fullText = '';
$pattern = sprintf('#%s#%s', join('', $regex), $regexOptions);
$introPattern = sprintf('#%s%s#%s', '^(.+?)', join('', $regex), $regexOptions); // match any page until first questions. There are exceptions in the pdf but not many
//preg_match('#^(.+?)\\n\s*(\d+)\s*\\t*(.+?)(P\.\))#is', $s, $introMatches);
$pages  = $pdf->getPages();
$i = 0;
foreach ($pages as $page) {
    ++$i;
    if ($i < 3) {
        continue;
    }
    $s = $page->getText();
    // cut the header, e.g.  \t90 – Navigation\t \tECQB\t-PPL(A)\t \t\nv20\t20.2 \t \t3 \t
    if (preg_match($introPattern, $s, $matchesIntro)) {
        $s = str_replace($matchesIntro[1], '', $s); // substr might be simpler maybe. Do we trust the length? Guess this will work.
    }
    // might be a good idea to cut the header/footer here
    $fullText .= $s;
}
// clean up a bit
unset($i, $s, $page, $pages, $pdf, $parser);
$numberOfQuestions = preg_match_all($pattern, $fullText, $matches, PREG_SET_ORDER, 0);
$split = preg_split($pattern, $fullText, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
$splitCount = count($split);
// warn if $split !== $numberOfQuestions * 4
if ($numberOfQuestions * 4 !== count($split)) :
    error_log("Unexpected number of text splits $numberOfQuestions * 4 !== $splitCount");
    die();
endif;
// let's chunk into questions:
$chunks = array_chunk($split, 4);
$return = array();
foreach ($chunks as $question) :
    $questionNumber = (int) $question[0];
    $return[$questionNumber] = array(
        'questionNumber' => $questionNumber,
        'questionText' => $question[1].$question[2],
        'answers' => array(),
        'attachments' => array()
    );
    $answers = preg_split("#(\u{f0a8}|\u{f0fe})#umi", $question[3], -1, PREG_SPLIT_DELIM_CAPTURE);
    $answerIntro = trim($answers[0]);
    if ($answerIntro === '') :
        unset($answers[0]);
    // capture a "Siehe Anlage 1" that follows a question
    elseif (is_numeric(($attachment = filter_var($answerIntro, FILTER_SANITIZE_NUMBER_INT)))) :
        $return[$questionNumber]['attachments'] = (int) $attachment;
        unset($answers[0]);
    endif;
    $numberOfAnswers = count($answers);
    if ($numberOfAnswers !== 8) :
        error_log("Unexpected number of answer splits $numberOfAnswers !== 8");
        die();
    endif;
    $answerChunks = array_chunk($answers, 2);

    foreach ($answerChunks as $answer) :
        $isCorrect = false;
        // incorrect: \u{f0a8}; correct \u{f0fe}
        //if (preg_match("#\u{f0fe}#ui", $answer[0])) :
        if ($answer[0] === "\u{f0fe}") : // less costly
            $isCorrect = true;
        endif;
        $return[$questionNumber]['answers'][] = array('answer' => $answer[1], 'isCorrect' => $isCorrect);
    endforeach;
endforeach;
header('Content-Type: application/json');
echo json_encode($return);

?>
