<?php
/**
 * This file is part of PHPWord - A pure PHP library for reading and writing
 * word processing documents.
 *
 * PHPWord is free software distributed under the terms of the GNU Lesser
 * General Public License version 3 as published by the Free Software Foundation.
 *
 * For the full copyright and license information, please read the LICENSE
 * file that was distributed with this source code. For the full list of
 * contributors, visit https://github.com/PHPOffice/PHPWord/contributors.
 *
 * @see         https://github.com/PHPOffice/PHPWord
 * @copyright   2010-2017 PHPWord contributors
 * @license     http://www.gnu.org/licenses/lgpl.txt LGPL version 3
 */

namespace PhpOffice\PhpWord;

use PhpOffice\PhpWord\Escaper\RegExp;
use PhpOffice\PhpWord\Escaper\Xml;
use PhpOffice\PhpWord\Exception\CopyFileException;
use PhpOffice\PhpWord\Exception\CreateTemporaryFileException;
use PhpOffice\PhpWord\Exception\Exception;
use PhpOffice\PhpWord\Shared\ZipArchive;
use Zend\Stdlib\StringUtils;

class TemplateProcessor
{
    const MAXIMUM_REPLACEMENTS_DEFAULT = -1;

    /**
     * ZipArchive object.
     *
     * @var mixed
     */
    protected $zipClass;

    /**
     * @var string Temporary document filename (with path)
     */
    protected $tempDocumentFilename;

    /**
     * Content of main document part (in XML format) of the temporary document
     *
     * @var string
     */
    protected $tempDocumentMainPart;

    /**
     * Content of headers (in XML format) of the temporary document
     *
     * @var string[]
     */
    protected $tempDocumentHeaders = array();

    /**
     * Content of footers (in XML format) of the temporary document
     *
     * @var string[]
     */
    protected $tempDocumentFooters = array();


    protected $_rels;
    protected $_types;
    protected $_countRels;

    protected $_lineBreak = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';

//    protected $_lineBreak = '<w:br w:type="page"/>';

    /**
     * @since 0.12.0 Throws CreateTemporaryFileException and CopyFileException instead of Exception
     *
     * @param string $documentTemplate The fully qualified template filename
     *
     * @throws \PhpOffice\PhpWord\Exception\CreateTemporaryFileException
     * @throws \PhpOffice\PhpWord\Exception\CopyFileException
     */
    public function __construct($documentTemplate)
    {
        // Temporary document filename initialization
        $this->tempDocumentFilename = tempnam(Settings::getTempDir(), 'PhpWord');
        if (false === $this->tempDocumentFilename) {
            throw new CreateTemporaryFileException();
        }

        // Template file cloning
        if (false === copy($documentTemplate, $this->tempDocumentFilename)) {
            throw new CopyFileException($documentTemplate, $this->tempDocumentFilename);
        }

        // Temporary document content extraction
        $this->zipClass = new ZipArchive();
        $this->zipClass->open($this->tempDocumentFilename);
        $index = 1;
        while (false !== $this->zipClass->locateName($this->getHeaderName($index))) {
            $this->tempDocumentHeaders[$index] = $this->fixBrokenMacros(
                $this->zipClass->getFromName($this->getHeaderName($index))
            );
            $index++;
        }
        $index = 1;
        while (false !== $this->zipClass->locateName($this->getFooterName($index))) {
            $this->tempDocumentFooters[$index] = $this->fixBrokenMacros(
                $this->zipClass->getFromName($this->getFooterName($index))
            );
            $index++;
        }
        $this->tempDocumentMainPart = $this->fixBrokenMacros($this->zipClass->getFromName($this->getMainPartName()));

        $this->_rels        = $this->zipClass->getFromName('word/_rels/document.xml.rels');
        $this->_types       = $this->zipClass->getFromName('[Content_Types].xml');
        $this->_countRels   = substr_count($this->_rels, 'Relationship') - 1;
    }

    /**
     * @param string $xml
     * @param \XSLTProcessor $xsltProcessor
     *
     * @throws \PhpOffice\PhpWord\Exception\Exception
     *
     * @return string
     */
    protected function transformSingleXml($xml, $xsltProcessor)
    {
        $domDocument = new \DOMDocument();
        if (false === $domDocument->loadXML($xml)) {
            throw new Exception('Could not load the given XML document.');
        }

        $transformedXml = $xsltProcessor->transformToXml($domDocument);
        if (false === $transformedXml) {
            throw new Exception('Could not transform the given XML document.');
        }

        return $transformedXml;
    }

    /**
     * @param mixed $xml
     * @param \XSLTProcessor $xsltProcessor
     *
     * @return mixed
     */
    protected function transformXml($xml, $xsltProcessor)
    {
        if (is_array($xml)) {
            foreach ($xml as &$item) {
                $item = $this->transformSingleXml($item, $xsltProcessor);
            }
        } else {
            $xml = $this->transformSingleXml($xml, $xsltProcessor);
        }

        return $xml;
    }

    /**
     * Applies XSL style sheet to template's parts.
     *
     * Note: since the method doesn't make any guess on logic of the provided XSL style sheet,
     * make sure that output is correctly escaped. Otherwise you may get broken document.
     *
     * @param \DOMDocument $xslDomDocument
     * @param array $xslOptions
     * @param string $xslOptionsUri
     *
     * @throws \PhpOffice\PhpWord\Exception\Exception
     */
    public function applyXslStyleSheet($xslDomDocument, $xslOptions = array(), $xslOptionsUri = '')
    {
        $xsltProcessor = new \XSLTProcessor();

        $xsltProcessor->importStylesheet($xslDomDocument);
        if (false === $xsltProcessor->setParameter($xslOptionsUri, $xslOptions)) {
            throw new Exception('Could not set values for the given XSL style sheet parameters.');
        }

        $this->tempDocumentHeaders = $this->transformXml($this->tempDocumentHeaders, $xsltProcessor);
        $this->tempDocumentMainPart = $this->transformXml($this->tempDocumentMainPart, $xsltProcessor);
        $this->tempDocumentFooters = $this->transformXml($this->tempDocumentFooters, $xsltProcessor);
    }

    /**
     * @param string $macro
     *
     * @return string
     */
    protected static function ensureMacroCompleted($macro)
    {
        if (substr($macro, 0, 2) !== '${' && substr($macro, -1) !== '}') {
            $macro = '${' . $macro . '}';
        }

        return $macro;
    }

    /**
     * @param string $subject
     *
     * @return string
     */
    protected static function ensureUtf8Encoded($subject)
    {
        if (!StringUtils::isValidUtf8($subject)) {
            $subject = utf8_encode($subject);
        }

        return $subject;
    }

    /**
     * @param mixed $search
     * @param mixed $replace
     * @param int $limit
     */
    public function setValue($search, $replace, $limit = self::MAXIMUM_REPLACEMENTS_DEFAULT)
    {
        if (is_array($search)) {
            foreach ($search as &$item) {
                $item = self::ensureMacroCompleted($item);
            }
        } else {
            $search = self::ensureMacroCompleted($search);
        }

        if (is_array($replace)) {
            foreach ($replace as &$item) {
                $item = self::ensureUtf8Encoded($item);
            }
        } else {
            $replace = self::ensureUtf8Encoded($replace);
        }

        if (Settings::isOutputEscapingEnabled()) {
            $xmlEscaper = new Xml();
            $replace = $xmlEscaper->escape($replace);
        }

        $this->tempDocumentHeaders = $this->setValueForPart($search, $replace, $this->tempDocumentHeaders, $limit);
        $this->tempDocumentMainPart = $this->setValueForPart($search, $replace, $this->tempDocumentMainPart, $limit);
        $this->tempDocumentFooters = $this->setValueForPart($search, $replace, $this->tempDocumentFooters, $limit);
    }

    /**
     * Returns array of all variables in template.
     *
     * @return string[]
     */
    public function getVariables()
    {
        $variables = $this->getVariablesForPart($this->tempDocumentMainPart);

        foreach ($this->tempDocumentHeaders as $headerXML) {
            $variables = array_merge($variables, $this->getVariablesForPart($headerXML));
        }

        foreach ($this->tempDocumentFooters as $footerXML) {
            $variables = array_merge($variables, $this->getVariablesForPart($footerXML));
        }

        return array_unique($variables);
    }

    /**
     * Clone a table row in a template document.
     *
     * @param string $search
     * @param int $numberOfClones
     *
     * @throws \PhpOffice\PhpWord\Exception\Exception
     */
    public function cloneRow($search, $numberOfClones)
    {
        if ('${' !== substr($search, 0, 2) && '}' !== substr($search, -1)) {
            $search = '${' . $search . '}';
        }

        $tagPos = strpos($this->tempDocumentMainPart, $search);
        if (!$tagPos) {
            throw new Exception('Can not clone row, template variable not found or variable contains markup.'.$search);
        }

        $rowStart = $this->findRowStart($tagPos);
        $rowEnd = $this->findRowEnd($tagPos);
        $xmlRow = $this->getSlice($rowStart, $rowEnd);

        // Check if there's a cell spanning multiple rows.
        if (preg_match('#<w:vMerge w:val="restart"/>#', $xmlRow)) {
            // $extraRowStart = $rowEnd;
            $extraRowEnd = $rowEnd;
            while (true) {
                $extraRowStart = $this->findRowStart($extraRowEnd + 1);
                $extraRowEnd = $this->findRowEnd($extraRowEnd + 1);

                // If extraRowEnd is lower then 7, there was no next row found.
                if ($extraRowEnd < 7) {
                    break;
                }

                // If tmpXmlRow doesn't contain continue, this row is no longer part of the spanned row.
                $tmpXmlRow = $this->getSlice($extraRowStart, $extraRowEnd);
                if (preg_match('#</w:tbl>#', $tmpXmlRow) ||
                   (!preg_match('#<w:vMerge/>#', $tmpXmlRow) &&
                    !preg_match('#<w:vMerge w:val="continue" />#', $tmpXmlRow))) {
                    break;
                }
                // This row was a spanned row, update $rowEnd and search for the next row.
                $rowEnd = $extraRowEnd;
            }
            $xmlRow = $this->getSlice($rowStart, $rowEnd);
        }

        $result = $this->getSlice(0, $rowStart);
        for ($i = 1; $i <= $numberOfClones; $i++) {
            $result .= preg_replace('/\$\{(.*?)\}/', '\${\\1#' . $i . '}', $xmlRow);
        }
        $result .= $this->getSlice($rowEnd);

        $this->tempDocumentMainPart = $result;
    }

    /**
     * Clone a block.
     *
     * @param string $blockname
     * @param int $clones
     * @param bool $replace
     *
     * @return string|null
     */
    public function cloneBlock($blockname, $clones = 1, $replace = true)
    {
        $xmlBlock = null;

        $matches = $this->findBlock($blockname);

        if (isset($matches[1])) {
            $xmlBlock = $matches[1];
            $cloned = array();
            for ($i = 1; $i <= $clones; $i++) {
                $xmlBlock = preg_replace('/\$\{(.*?)\}/', '\${\\1#' . $i . '}', $matches[1]);

                //DELETE SALTOS DE LINEA
                $xmlBlock = str_replace('<w:r>'.$this->_lineBreak.'</w:r>','',$xmlBlock);
                $cloned[] = $xmlBlock;
            }

            if ($replace) {
                $this->tempDocumentMainPart = str_replace(
                    $matches[0],
                    implode('', $cloned),
                    $this->tempDocumentMainPart
                );
            }
        }

        return $xmlBlock;
    }

    /**
     * Replace a block.
     *
     * @param string $blockname
     * @param string $replacement
     */
    public function replaceBlock($blockname, $replacement)
    {
        $matches = $this->findBlock($blockname);

        if (isset($matches[1])) {
            $this->tempDocumentMainPart = str_replace(
                $matches[0],
                $replacement,
                $this->tempDocumentMainPart
            );
        }
    }

    /**
     * Delete a block of text.
     *
     * @param string $blockname
     */
    public function deleteBlock($blockname)
    {
        $this->replaceBlock($blockname, '');
    }

    /**
     * Saves the result document.
     *
     * @throws \PhpOffice\PhpWord\Exception\Exception
     *
     * @return string
     */
    public function save()
    {
        foreach ($this->tempDocumentHeaders as $index => $xml) {
            $this->zipClass->addFromString($this->getHeaderName($index), $xml);
        }

        $this->zipClass->addFromString($this->getMainPartName(), $this->tempDocumentMainPart);

        if($this->_rels != "") {
            $this->zipClass->addFromString('word/_rels/document.xml.rels', $this->_rels);
        }

        if($this->_types != "") {
            $this->zipClass->addFromString('[Content_Types].xml', $this->_types);
        }

        foreach ($this->tempDocumentFooters as $index => $xml) {
            $this->zipClass->addFromString($this->getFooterName($index), $xml);
        }

        // Close zip file
        if (false === $this->zipClass->close()) {
            throw new Exception('Could not close zip file.');
        }

        return $this->tempDocumentFilename;
    }

    /**
     * Saves the result document to the user defined file.
     *
     * @since 0.8.0
     *
     * @param string $fileName
     */
    public function saveAs($fileName)
    {
        $tempFileName = $this->save();

        if (file_exists($fileName)) {
            unlink($fileName);
        }

        /*
         * Note: we do not use `rename` function here, because it looses file ownership data on Windows platform.
         * As a result, user cannot open the file directly getting "Access denied" message.
         *
         * @see https://github.com/PHPOffice/PHPWord/issues/532
         */
        copy($tempFileName, $fileName);
        unlink($tempFileName);
    }

    /**
     * Finds parts of broken macros and sticks them together.
     * Macros, while being edited, could be implicitly broken by some of the word processors.
     *
     * @param string $documentPart The document part in XML representation
     *
     * @return string
     */
    protected function fixBrokenMacros($documentPart)
    {
        $fixedDocumentPart = $documentPart;

        $fixedDocumentPart = preg_replace_callback(
            '|\$[^{]*\{[^}]*\}|U',
            function ($match) {
                return strip_tags($match[0]);
            },
            $fixedDocumentPart
        );

        return $fixedDocumentPart;
    }

    /**
     * Find and replace macros in the given XML section.
     *
     * @param mixed $search
     * @param mixed $replace
     * @param string $documentPartXML
     * @param int $limit
     *
     * @return string
     */
    protected function setValueForPart($search, $replace, $documentPartXML, $limit)
    {
        // Note: we can't use the same function for both cases here, because of performance considerations.
        if (self::MAXIMUM_REPLACEMENTS_DEFAULT === $limit) {
            return str_replace($search, $replace, $documentPartXML);
        }
        $regExpEscaper = new RegExp();

        return preg_replace($regExpEscaper->escape($search), $replace, $documentPartXML, $limit);
    }

    /**
     * Find all variables in $documentPartXML.
     *
     * @param string $documentPartXML
     *
     * @return string[]
     */
    protected function getVariablesForPart($documentPartXML)
    {
        preg_match_all('/\$\{(.*?)}/i', $documentPartXML, $matches);

        return $matches[1];
    }

    /**
     * Get the name of the header file for $index.
     *
     * @param int $index
     *
     * @return string
     */
    protected function getHeaderName($index)
    {
        return sprintf('word/header%d.xml', $index);
    }

    /**
     * @return string
     */
    protected function getMainPartName()
    {
        return 'word/document.xml';
    }

    /**
     * Get the name of the footer file for $index.
     *
     * @param int $index
     *
     * @return string
     */
    protected function getFooterName($index)
    {
        return sprintf('word/footer%d.xml', $index);
    }

    /**
     * Find the start position of the nearest table row before $offset.
     *
     * @param int $offset
     *
     * @throws \PhpOffice\PhpWord\Exception\Exception
     *
     * @return int
     */
    protected function findRowStart($offset)
    {
        $rowStart = strrpos($this->tempDocumentMainPart, '<w:tr ', ((strlen($this->tempDocumentMainPart) - $offset) * -1));

        if (!$rowStart) {
            $rowStart = strrpos($this->tempDocumentMainPart, '<w:tr>', ((strlen($this->tempDocumentMainPart) - $offset) * -1));
        }
        if (!$rowStart) {
            throw new Exception('Can not find the start position of the row to clone.');
        }

        return $rowStart;
    }

    /**
     * Find the end position of the nearest table row after $offset.
     *
     * @param int $offset
     *
     * @return int
     */
    protected function findRowEnd($offset)
    {
        return strpos($this->tempDocumentMainPart, '</w:tr>', $offset) + 7;
    }

    /**
     * Get a slice of a string.
     *
     * @param int $startPosition
     * @param int $endPosition
     *
     * @return string
     */
    protected function getSlice($startPosition, $endPosition = 0)
    {
        if (!$endPosition) {
            $endPosition = strlen($this->tempDocumentMainPart);
        }

        return substr($this->tempDocumentMainPart, $startPosition, ($endPosition - $startPosition));
    }

    protected function findBlock($blockname)
    {
        // Parse the XML
        $xml = new \SimpleXMLElement($this->tempDocumentMainPart);

        // Find the starting and ending tags
        $startNode = false; $endNode = false;
        foreach ($xml->xpath('//w:t') as $node) {
            if (strpos($node, '${'.$blockname.'}') !== false) {
                $startNode = $node;
                continue;
            }

            if (strpos($node, '${/'.$blockname.'}') !== false) {
                $endNode = $node;
                break;
            }
        }

        // Make sure we found the tags
        if ($startNode === false || $endNode === false) {
            return null;
        }

        // Find the parent <w:p> node for the start tag
        $node = $startNode; $startNode = null;
        while (is_null($startNode)) {
            $node = $node->xpath('..')[0];

            if ($node->getName() == 'p') {
                $startNode = $node;
            }
        }

        // Find the parent <w:p> node for the end tag
        $node = $endNode; $endNode = null;
        while (is_null($endNode)) {
            $node = $node->xpath('..')[0];

            if ($node->getName() == 'p') {
                $endNode = $node;
            }
        }

        $this->tempDocumentMainPart = $xml->asXml();

        // Find the xml in between the tags
        $xmlBlock = null;
        preg_match(
            '/'.preg_quote($startNode->asXml(), '/').'(.*?)'.preg_quote($endNode->asXml(), '/').'/is',
            $this->tempDocumentMainPart,
            $matches
        );

        return $matches;
    }

    // REEMPLAZAR STRING POR IMÁGENES FUNCIONES OPCIONALES
    public function replaceImgs( $search, $replace )
    {
        if (!array($search)) {
            $search = array($search);
        }

        $relationTmpl = '<Relationship Id="RID" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/IMG"/>';
        $typeTmpl = ' <Override PartName="/word/media/IMG" ContentType="image/EXT"/>';
        $aSearch = array('RID', 'IMG');
        $aSearchType = array('IMG', 'EXT');
        $toAddImgs = array();
        $toAdd = $toAddType = '';

        foreach ($search as &$item) {
            $item = '<w:t>' . self::ensureMacroCompleted($item) . '</w:t>';
        }

        foreach ($replace as $imgPath) {
            $imgData = getimagesize($imgPath);
            $imgWidth = (int)($imgData[0]*2/3);
            $imgHeight = (int)($imgData[1]*2/3);

            $imgTmpl = '<w:pict><v:shape type="#_x0000_t75" style="width:'.$imgWidth.'px;height:'.$imgHeight.'px"><v:imagedata r:id="RID" o:title=""/></v:shape></w:pict>';

            $imgArray = explode('.', $imgPath);

            $imgExt = array_pop( $imgArray );

            if( in_array($imgExt, array('jpg', 'JPG') ) ) {
                $imgExt = 'jpeg';
            }
            $imgName = 'img' . $this->_countRels . '.' . $imgExt;
            $rid = 'rId' . $this->_countRels++;

            $this->zipClass->addFile($imgPath, 'word/media/' . $imgName);

            $toAddImgs[] = str_replace('RID', $rid, $imgTmpl) ;

            $aReplace = array($imgName, $imgExt);
            $toAddType .= str_replace($aSearchType, $aReplace, $typeTmpl) ;

            $aReplace = array($rid, $imgName);
            $toAdd .= str_replace($aSearch, $aReplace, $relationTmpl);
        }

        $this->tempDocumentMainPart = str_replace($search, $toAddImgs, $this->tempDocumentMainPart);
        $this->_types = str_replace('</Types>', $toAddType, $this->_types) . '</Types>';
        $this->_rels = str_replace('</Relationships>', $toAdd, $this->_rels) . '</Relationships>';
    }

    public function insertLinesBreaks($search) {
        if (!array($search)) {
            $search = array($search);
        }

        $replaces = array();

        // Parse the XML
        $xml = new \SimpleXMLElement($this->tempDocumentMainPart);

        foreach ($search as $blockname) {
            $lines = $this->_returnLineDoc($xml,$blockname);

            foreach ($lines as $line) {
                $replaces[] = $line;
            }
        }

        $this->tempDocumentMainPart = str_replace($replaces, $this->_lineBreak, $this->tempDocumentMainPart);

        $stringsExcept = array(
            $this->_lineBreak.$this->_lineBreak,
            '<w:p><w:r><w:br w:type="page"/></w:r></w:p><w:p w14:paraId="177429B1" w14:textId="77777777" w:rsidR="00434B1F" w:rsidRDefault="00434B1F"><w:pPr><w:spacing w:after="0"/><w:ind w:firstLine="0"/><w:jc w:val="left"/><w:rPr><w:rFonts w:ascii="Open Sans Bold" w:hAnsi="Open Sans Bold"/><w:caps/><w:color w:val="00686E" w:themeColor="accent1"/><w:sz w:val="32"/><w:szCs w:val="32"/><w:lang w:val="es-ES_tradnl"/></w:rPr></w:pPr><w:r><w:br w:type="page"/></w:r></w:p>',
            '<w:p><w:r><w:br w:type="page"/></w:r></w:p><w:p w14:paraId="11F9D35D" w14:textId="77777777" w:rsidR="00434B1F" w:rsidRDefault="00434B1F" w:rsidP="00434B1F"/><w:p w14:paraId="473B8083" w14:textId="77777777" w:rsidR="00434B1F" w:rsidRDefault="00434B1F"><w:pPr><w:spacing w:after="0"/><w:ind w:firstLine="0"/><w:jc w:val="left"/></w:pPr><w:r><w:br w:type="page"/></w:r></w:p>',
            '<w:p><w:r><w:br w:type="page"/></w:r></w:p><w:p w14:paraId="7747A33E" w14:textId="61E2AF56" w:rsidR="006709B3" w:rsidRDefault="006709B3" w:rsidP="006709B3"><w:pPr><w:pStyle w:val="Titular1"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="0"/></w:numPr><w:spacing w:before="120" w:line="276" w:lineRule="auto"/><w:rPr><w:rFonts w:ascii="Lato Light" w:hAnsi="Lato Light"/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:pPr><w:r w:rsidRPr="00CE083D"><w:rPr><w:rFonts w:ascii="Lato Light" w:hAnsi="Lato Light"/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr><w:t xml:space="preserve"> </w:t></w:r></w:p><w:p w14:paraId="506D6C41" w14:textId="2D53A349" w:rsidR="006709B3" w:rsidRDefault="006709B3" w:rsidP="006709B3"><w:pPr><w:pStyle w:val="Titular1"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="0"/></w:numPr><w:spacing w:before="120" w:line="276" w:lineRule="auto"/><w:rPr><w:rFonts w:ascii="Lato Light" w:hAnsi="Lato Light"/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:pPr></w:p><w:p w14:paraId="7CABEBB3" w14:textId="64EEABFB" w:rsidR="006709B3" w:rsidRDefault="006709B3" w:rsidP="006709B3"><w:pPr><w:pStyle w:val="Titular1"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="0"/></w:numPr><w:spacing w:before="120" w:line="276" w:lineRule="auto"/><w:rPr><w:rFonts w:ascii="Lato Light" w:hAnsi="Lato Light"/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:pPr></w:p><w:p w14:paraId="3348A815" w14:textId="77777777" w:rsidR="006709B3" w:rsidRPr="00CE083D" w:rsidRDefault="006709B3" w:rsidP="006709B3"><w:pPr><w:pStyle w:val="Titular1"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="0"/></w:numPr><w:spacing w:before="120" w:line="276" w:lineRule="auto"/><w:rPr><w:rFonts w:ascii="Lato Light" w:hAnsi="Lato Light"/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr></w:pPr></w:p><w:p w14:paraId="5F436D17" w14:textId="77777777" w:rsidR="006709B3" w:rsidRDefault="006709B3"><w:pPr><w:spacing w:after="0"/><w:ind w:firstLine="0"/><w:jc w:val="left"/></w:pPr></w:p><w:p w14:paraId="7F8232D2" w14:textId="77777777" w:rsidR="006709B3" w:rsidRDefault="006709B3"><w:pPr><w:spacing w:after="0"/><w:ind w:firstLine="0"/><w:jc w:val="left"/></w:pPr></w:p><w:p w14:paraId="226FF353" w14:textId="77777777" w:rsidR="006709B3" w:rsidRDefault="006709B3"><w:pPr><w:spacing w:after="0"/><w:ind w:firstLine="0"/><w:jc w:val="left"/></w:pPr></w:p><w:p w14:paraId="241EEBAF" w14:textId="5F10CC06" w:rsidR="004A4DFD" w:rsidRDefault="004A4DFD"><w:pPr><w:spacing w:after="0"/><w:ind w:firstLine="0"/><w:jc w:val="left"/></w:pPr><w:r><w:br w:type="page"/></w:r></w:p>',
            '<w:p><w:r><w:br w:type="page"/></w:r></w:p><w:p w14:paraId="177429B1" w14:textId="04EA55BF" w:rsidR="00434B1F" w:rsidRDefault="00434B1F"><w:pPr><w:spacing w:after="0"/><w:ind w:firstLine="0"/><w:jc w:val="left"/><w:rPr><w:rFonts w:ascii="Open Sans Bold" w:hAnsi="Open Sans Bold"/><w:caps/><w:color w:val="00686E" w:themeColor="accent1"/><w:sz w:val="32"/><w:szCs w:val="32"/><w:lang w:val="es-ES_tradnl"/></w:rPr></w:pPr><w:r><w:br w:type="page"/></w:r></w:p>'
        );

        $this->tempDocumentMainPart = str_replace($stringsExcept, $this->_lineBreak, $this->tempDocumentMainPart);

        return true;
    }

    public function deleteWhiteLines($search) {
        if (!array($search)) {
            $search = array($search);
        }

        $replaces = array();

        // Parse the XML
        $xml = new \SimpleXMLElement($this->tempDocumentMainPart);

        foreach ($search as $blockname) {
            $lines = $this->_returnLineDoc($xml,$blockname);

            foreach ($lines as $line) {
                $replaces[] = $line;
            }
        }

        $this->tempDocumentMainPart = str_replace($replaces, '', $this->tempDocumentMainPart);
    }

    protected function _returnLineDoc($xml,$blockname) {
        // Find the starting and ending tags
        $nodes = array();
        $parts = array();

        foreach ($xml->xpath('//w:t') as $node) {
            if (strpos($node, '${'.$blockname.'}') !== false) {
                $nodes[] = $node;
            }
        }

        // Make sure we found the tags
        if (count($nodes)) {
            foreach($nodes as $startNode) {
                $node = $startNode;
                $startNode = null;
                while (is_null($startNode)) {
                    $node = $node->xpath('..')[0];
                    if ($node->getName() == 'p') {
                        $startNode = $node;
                    }
                }

                $parts[] = $startNode->asXml();
            }
        }

        return $parts;
    }
}
