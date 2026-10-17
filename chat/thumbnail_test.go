package main

import (
	"bytes"
	"encoding/binary"
	"hash/crc32"
	"image"
	"image/png"
	"testing"
)

// A tiny PNG whose header claims 60000x60000 must not be decoded: the
// decoder would allocate ~14 GB and crash the service.
func TestThumbnailRefusesHugeImages(t *testing.T) {
	var buf bytes.Buffer
	if err := png.Encode(&buf, image.NewGray(image.Rect(0, 0, 1, 1))); err != nil {
		t.Fatal(err)
	}
	b := buf.Bytes()
	// IHDR data starts at 16: width, height; its CRC covers type+data (12..29).
	binary.BigEndian.PutUint32(b[16:], 60000)
	binary.BigEndian.PutUint32(b[20:], 60000)
	binary.BigEndian.PutUint32(b[29:], crc32.ChecksumIEEE(b[12:29]))

	if _, ok := makeThumbnail(bytes.NewReader(b), ".png"); ok {
		t.Fatal("a 3.6 gigapixel image got a thumbnail")
	}

	var small bytes.Buffer
	_ = png.Encode(&small, image.NewGray(image.Rect(0, 0, 640, 480)))
	if _, ok := makeThumbnail(bytes.NewReader(small.Bytes()), ".png"); !ok {
		t.Fatal("a normal image got no thumbnail")
	}
}
