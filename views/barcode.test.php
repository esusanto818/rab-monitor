<?php
session_start();
include_once "../php-class/crudClass.php";
require_once('../tcpdf_new/config/lang/eng.php');
require_once('../tcpdf_new/tcpdf.php');
    
   
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false); 
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('IT Development Team');
$pdf->SetTitle('KMGI Integrated System');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(5, 5, 5);
$pdf->SetAutoPageBreak(TRUE, 4);
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
$pdf->setLanguageArray($l);
$pdf->setBarcode(date('Y-m-d H:i:s'));
$pdf->SetFont('helveticaB', '', 10);
$pdf->AddPage('L', 'F4');


$style = array(
    'stretch' => false,
    'fitwidth' => true,
    'cellfitalign' => '',
    'border' => false,
    'hpadding' => 'auto',
    'vpadding' => 'auto',
    'fgcolor' => array(0, 0, 0),
    'bgcolor' => false, 
    'text' => false,
    'font' => 'helvetica',
    'fontsize' => 6,
    'stretchtext' => 4
);
$ketentuan=['','Ketentuan ID Card',
'1. ID Card ini wajib di gunakan saat berada di lokasi perusahaan KMGI.',
'2. ID Card ini tidak bisa di gunakan atau di pindahtangankan kepada orang lain.',
'3. ID Card di gunakan sebagai tanda pengenal dan kartu absensi selama yang bersangkutan menjadi karyawan/bekerja di KMGI',
'4. Jika ID Card ini hilang,yang bersangkutan di kenai denda dan penggantian biaya cetak sebesar Rp.25.000.',
'5. Jika ada yang menemukan ID Card ini mohon kembalikan ke pada bagian HRD KMGI',
'6. Penyalahgunakan ID Card ini bukan tanggung jawab perusahaan.'];
$position=['C','C','L','L','L','L','L','L'];
for ($n=0; $n<8; $n++) {
  $textCard=$ketentuan[$n];
  if ($n<=1) {$pdf->SetFont('helvetica', '', 12);} else {  $pdf->SetFont('helvetica', '', 7.5);}
  $rowcount = ($n==0) ? 8 : $pdf->getStringHeight(58,$textCard,true,true,'',1)+2;
  for ($i=1; $i<=5; $i++){
    $pdf->MultiCell(58, $rowcount, $textCard, 0, $position[$n], 0, 0, '', '', true, 0, false, true, $rowcount, 'M');
    $pos=($i<5) ? 0 : 1;
    $pdf->Cell(6, $rowcount, '', 0, $pos, 'C', false, '', 1); 
  }
}
$pdf->Cell(5, 18, '', 0, 1, 'C', false, '', 1); 
for ($n=0; $n<8; $n++) {
  $textCard=$ketentuan[$n];
  if ($n<=1) {$pdf->SetFont('helvetica', '', 12);} else {  $pdf->SetFont('helvetica', '', 7.5);}
  $rowcount = ($n==0) ? 8 : $pdf->getStringHeight(58,$textCard,true,true,'',1)+2;
  for ($i=1; $i<=5; $i++){
    $pdf->MultiCell(58, $rowcount, $textCard, 0, $position[$n], 0, 0, '', '', true, 0, false, true, $rowcount, 'M');
    $pos=($i<5) ? 0 : 1;
    $pdf->Cell(6, $rowcount, '', 0, $pos, 'C', false, '', 1); 
  }
}
//$pdf->Rect(2, 15, 58, 75, 'D');
//$pdf->Rect(2, 115, 58, 75, 'D');

/*
$y=2; $z=15;
for ($x=1; $x<=10; $x++){
  $pdf->SetTextColor(255,255,255);
  if($x==6){$pdf->Cell(0,100,'',0,1); $y=2; $z=115;}
  $pdf->Cell(58, 13, 'CV. KMGI - GRESIK', 1, 0, 'C', '#000', '', 1);
  $pdf->Cell(5, 13, '', 0, 0, 'C', false, '', 1);
  $pdf->SetTextColor(0,0,0);
  $pdf->Rect($y, $z, 58, 75, 'D');
  $y+=63;
}
*/
  /*$pdf->SetFont('helveticaB', '', 12);
  $pdf->SetTextColor(255,255,255);
  $pdf->Cell(58, 13, 'CV. KMGI - GRESIK', 1, 0, 'C', '#000', '', 1);
  $pdf->Cell(5, 13, '', 0, 0, 'C', false, '', 1);
  $pdf->Cell(58, 13, 'CV. KMGI - GRESIK', 1, 0, 'C', '#000', '', 1);
  $pdf->Cell(5, 13, '', 0, 0, 'C', false, '', 1);
  $pdf->Cell(58, 13, 'CV. KMGI - GRESIK', 1, 0, 'C', '#000', '', 1);
  $pdf->Cell(5, 13, '', 0, 0, 'C', false, '', 1);
  $pdf->Cell(58, 13, 'CV. KMGI - GRESIK', 1, 0, 'C', '#000', '', 1);
  $pdf->Cell(5, 13, '', 0, 0, 'C', false, '', 1);
  $pdf->Cell(58, 13, 'CV. KMGI - GRESIK', 1, 1, 'C', '#000', '', 1);

  $pdf->SetTextColor(0,0,0);
  $pdf->Rect(2, 15, 58, 75, 'D');
  $pdf->Cell(0,50,'',0,1);
  $pdf->Cell(58, 6, 'Eddy Susanto', 0, 1, 'C', false, '', 1);
  $pdf->Cell(58, 6, 'ITD', 0, 1, 'C', false, '', 1);
  $pdf->write1DBarcode('300800026', 'C39', 9.5, 77, 43, 12, 0.4, $style, 'C');*/
  /*$pdf->Rect(65, 15, 58, 75, 'D');
  $pdf->Rect(128, 15, 58, 75, 'D');
  $pdf->Rect(191, 15, 58, 75, 'D');
  $pdf->Rect(254, 15, 58, 75, 'D');*/

  /*
  $pdf->SetTextColor(255,255,255);
  $pdf->Cell(0,20,'',0,1);
  $pdf->Cell(58, 13, 'CV. KMGI - GRESIK', 1, 0, 'C', '#000', '', 1);
  $pdf->Cell(5, 13, '', 0, 0, 'C', false, '', 1);
  $pdf->Cell(58, 13, 'CV. KMGI - GRESIK', 1, 0, 'C', '#000', '', 1);
  $pdf->Cell(5, 13, '', 0, 0, 'C', false, '', 1);
  $pdf->Cell(58, 13, 'CV. KMGI - GRESIK', 1, 0, 'C', '#000', '', 1);
  $pdf->Cell(5, 13, '', 0, 0, 'C', false, '', 1);
  $pdf->Cell(58, 13, 'CV. KMGI - GRESIK', 1, 0, 'C', '#000', '', 1);
  $pdf->Cell(5, 13, '', 0, 0, 'C', false, '', 1);
  $pdf->Cell(58, 13, 'CV. KMGI - GRESIK', 1, 1, 'C', '#000', '', 1);

  $pdf->Rect(2, 110, 58, 75, 'D');
  $pdf->Rect(65, 110, 58, 75, 'D');
  $pdf->Rect(128, 110, 58, 75, 'D');
  $pdf->Rect(191, 110, 58, 75, 'D');
  $pdf->Rect(254, 110, 58, 75, 'D');
  */
  //$pdf->write1DBarcode('300800026', 'C39', 30, 40, 43, 12, 0.4, $style, 'C');
  //$pdf->write1DBarcode('300800026', 'C39', 30, 140, 43, 12, 0.3, $style, 'C');
ob_end_clean();

$pdf->Output('barcode.pdf', 'I');

?>
