#!/bin/bash
# One-time: lay out the USB flash drive as a bootable rescue copy of the SD card
# plus the backup area, then make the first copy. ERASES THE DRIVE.
#
#   sudo bash usb-rescue-setup.sh [/dev/sdX]      (after pi-update.sh; default /dev/sda)
#
# Partitions (MBR with its own disk id, so its PARTUUIDs never clash with the SD card's):
#   1  512 MiB  FAT32  RESCUEBOOT   /boot/firmware of the rescue copy
#   2   16 GiB  ext4   rescueroot   / of the rescue copy, kept fresh by usb-rescue-sync
#   3   rest    ext4   usb          /mnt/usb: database backups and SD card images
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo: sudo bash $0"; exit 1; }

dev=${1:-/dev/sda}
[ -x /usr/local/sbin/usb-rescue-sync ] || { echo "Run pi-update.sh first"; exit 1; }
[ "$(findmnt -no SOURCE /)" = /dev/mmcblk0p2 ] || { echo "Stop: the Pi isn't running from the SD card"; exit 1; }
[ "$(lsblk -dno TRAN "$dev" 2>/dev/null)" = usb ] || { echo "Stop: $dev is not a USB drive"; exit 1; }
for unit in sd-image-backup usb-rescue-sync chesscodex-backup; do
    if systemctl is-active -q $unit.service; then echo "Stop: $unit is running, try again later"; exit 1; fi
done

echo "Everything on $dev ($(lsblk -dno MODEL,SIZE "$dev" | xargs)) will be erased."
read -r -p "Type YES to continue: " ok
[ "$ok" = YES ] || { echo "Cancelled"; exit 1; }

umount /mnt/usb 2>/dev/null || true
if lsblk -no MOUNTPOINTS "$dev" | grep -q .; then echo "Stop: $dev is still mounted"; exit 1; fi
wipefs -aq "$dev"?* "$dev" 2>/dev/null || wipefs -aq "$dev"

sd_id=$(lsblk -dno PTUUID /dev/mmcblk0)
id=$sd_id
while [ "$id" = "$sd_id" ]; do id=$(od -An -N4 -tx4 /dev/urandom | tr -d ' '); done
sfdisk -q "$dev" <<EOF
label: dos
label-id: 0x$id

,512MiB,c
,16GiB,83
,,83
EOF
udevadm settle
mkfs.vfat -F 32 -n RESCUEBOOT "${dev}1" >/dev/null
mkfs.ext4 -q -F -L rescueroot "${dev}2"
mkfs.ext4 -q -F -L usb -m 0 "${dev}3"
udevadm settle

uuid=$(blkid -s UUID -o value "${dev}3")
sed -i '\#[[:space:]]/mnt/usb[[:space:]]#d' /etc/fstab
echo "UUID=$uuid /mnt/usb ext4 defaults,noatime,nofail,x-systemd.device-timeout=5s 0 2" >> /etc/fstab
systemctl daemon-reload
mkdir -p /mnt/usb
mount /mnt/usb
chown "${SUDO_USER:-root}:" /mnt/usb

echo "== first rescue copy (about 5 minutes)"
systemctl start usb-rescue-sync.service
journalctl -u usb-rescue-sync -n 1 -o cat --no-pager
echo "== SD card image: started in the background (about 12 minutes)"
systemctl start --no-block sd-image-backup.service
lsblk -o NAME,SIZE,FSTYPE,LABEL,MOUNTPOINTS "$dev"
echo "DONE"
