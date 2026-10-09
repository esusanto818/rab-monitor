<?php
session_start();
require_once('../tcpdf_new/config/lang/eng.php');
require_once('../tcpdf_new/tcpdf.php');

class MYPDF extends TCPDF {
  var $MyPDFHeaderTitle;
  var $MyPDFHeaderTable;
  var $MyPDFHeaderTableColWidth;
  var $MyPDFHeaderTableStart;
  var $MyPDFFooterWidth;
  var $MyHeaderTitlePos;
  var $MyHeaderMailPos;
  var $MyPDFPageLength;
  var $MyPDFHeaderType;

  public function setMyPDFLength($MyPDFPageLength) {$this->MyPDFPageLength = $MyPDFPageLength;}
  public function setMyPDFheader($MyPDFHeaderTitle) {$this->MyPDFHeaderTitle = $MyPDFHeaderTitle;}
  public function setMyPDFTableHeaderStart($MyPDFHeaderTableStart = 1) {
    if ($MyPDFHeaderTableStart>1) {$this->MyPDFHeaderTableStart = 1.2+$MyPDFHeaderTableStart;}
    else {$this->MyPDFHeaderTableStart = 1.2;}
  }
  public function setMyPDFTableHeader($MyPDFHeaderTable) {$this->MyPDFHeaderTable = $MyPDFHeaderTable;}
  public function setMyPDFTableHeaderColWidth($MyPDFHeaderTableColWidth) {$this->MyPDFHeaderTableColWidth = $MyPDFHeaderTableColWidth;}
  public function setMyPDFFooterWidth($MyPDFFooterWidth = 201) {
    $this->MyPDFFooterWidth = $MyPDFFooterWidth;
    $this->MyHeaderTitlePos = $MyPDFFooterWidth-74;
    $this->MyHeaderMailPos = $MyPDFFooterWidth-20;
  }
  public function setMyPDFHeaderType($MyPDFHeaderType = 'report') {$this->MyPDFHeaderType = $MyPDFHeaderType;}

  public function CreateTextBox($textval, $x = 0, $y, $width = 0, $height = 4, $border = 0, $fontname = 'helvetica', $fontsize = 10, $fontstyle = '', $align = 'L') {
    $this->SetXY($x+5, $y); // +5 = margin left
    $this->SetFont($fontname, $fontstyle, $fontsize);
    //$this->Cell($width, $height, $textval, $border, false, $align, false, '', 0,'','','T');
    //Cell( $w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '', $stretch = 0, $ignore_min_height = false, $calign = 'T', $valign = 'M' )
    $this->MultiCell($width, $height, $textval, $border, $align);
    //MultiCell( $w, $h, $txt, $border = 0, $align = 'J', $fill = false, $ln = 1, $x = '', $y = '', $reseth = true, $stretch = 0, $ishtml = false, $autopadding = true, $maxh = 0, $valign = 'T', $fitcell = false )
  }

  public function Header() {
    // get the current page break margin.
    $bMargin = $this->getBreakMargin();
    // get current auto-page-break mode.
    $auto_page_break = $this->getAutoPageBreak();
    // enable auto page break.
    $this->SetAutoPageBreak($auto_page_break, $bMargin);
    $this->SetLeftMargin(5);
    $this->SetAuthor('IT Development Team');
    $this->Image('../img/kmgi_new.png',6,3,27,16);
    $this->SetLineWidth(.1);
    $this->Line(5,20,$this->MyPDFFooterWidth+5,20);

    if ($this->MyPDFHeaderType=='form') {
      $this->SetTextColor(187, 187, 187);
      $this->CreateTextBox($this->MyPDFHeaderTitle, 100, 10, 102, 10, 0 ,'times',  20, 'BI', 'R');
      $this->SetTextColor(0, 0, 0);
      $this->CreateTextBox('Kayu Multiguna Indonesia', 0, 20, 70, 0, 0, '',10 ,'BI' ,'L');
      $this->CreateTextBox('Jl. Veteran Segoromadu', 0, 24, 70, 0, 0, '',8 ,'I' ,'L');
      $this->CreateTextBox('Kel. Gending Kec. Kebomas Gresik. 61123', 0, 28, 70, 0, 0, '',8 ,'I' ,'L');
      $this->CreateTextBox('Telp : 031-3975620, Fax : 031-3975620', 0, 32, 70, 0, 0, '',8 ,'I' ,'L');
    } else {
      $this->SetLeftMargin(25);
      $this->SetY(5);
      $this->SetFont('helvetica','BI',13);
      $this->Cell(54,0,'Kayu Multiguna',0,1,'C');
      $this->SetFont('helvetica','I',11);
      $this->Cell(54,0,'multigunawood.com',0,0,'C');
      $this->Cell($this->MyHeaderTitlePos,0,$this->MyPDFHeaderTitle,0,1,'R');
      $this->SetFont('courier', '', 7);
      $this->SetFont('helvetica','I',8);
      $this->Cell($this->MyHeaderMailPos,0,'mail: sales@multigunawood.com',0,1,'R');
    }

    $this->SetLeftMargin(5);
    $this->Ln($this->MyPDFHeaderTableStart);

    $this->SetFillColor(0, 0, 0);
    $this->SetTextColor(255, 255, 255);
    $this->SetDrawColor(255, 255, 255);
    $this->SetLineWidth(0.3);
    $this->SetFont('helvetica','BI',9);
    $header = $this->MyPDFHeaderTable;
    $w = $this->MyPDFHeaderTableColWidth;
    $num_headers = count($header);
    for($i = 0; $i < $num_headers; ++$i) {$this->Cell($w[$i], 7, $header[$i], 1, 0, 'C', 1);}
    $this->Ln();
  }
  
  public function Footer() {
    if ($this->MyPDFPageLength=='half') {$this->SetY(-139);}
    else {$this->SetY(-10);}

    $this->SetFont('helvetica', 'I', 7);
    $this->Cell($this->MyPDFFooterWidth,3,'This is computer generated documents, printed by '.$_SESSION['user'].' printed date '.date("j F, Y, H : i : s"),0,1,'R');
    $this->SetFont('helvetica', 'I', 8);
    $this->Cell($this->MyPDFFooterWidth,3, 'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, 1, 'C');
  }
  
  public function CreateApproveBox($boxShow = false, $t1, $t2, $t3) {
    if ($boxShow==true) {
      if ($this->MyPDFPageLength=='half') {$this->SetY(-160);} else {$this->SetY(-30);}
      $this->SetFillColor(216, 212, 210);
      $this->SetTextColor(0,0,0);
      $this->SetFont('helvetica','',9);
      $this->Cell(67.5,0,$t1,1,0,'C',1);
      $this->Cell(67.5,0,$t2,1,0,'C',1);
      $this->Cell(67.5,0,$t3,1,1,'C',1);
    }
  }
}
?>