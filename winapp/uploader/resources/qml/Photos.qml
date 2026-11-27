// Project: PHP Gallery
// Repository: https://github.com/klusik/PHP_gallery
// File: winapp/uploader/resources/qml/Photos.qml
// Module Type: Qt Quick photos view
// Purpose: Render reusable photo tiles and a transform-only retained lightbox.
// Responsibilities: Report viewport identity and emit selection and navigation.
// Author: Rudolf Klusal
// License: MIT License (see LICENSE file in repository)

import QtQuick

Rectangle {
    id: scene
    color: desktopColors.card_surface

    // Type: int. Units: logical pixels. Scope: responsive Photos grid.
    // Consumers: tile geometry. Rationale: the shared raster supplies its
    // format size, 24 fits a local filename, and the authorized grid uses a
    // 6 px Tk inter-card gutter plus a 4 px frame allowance on both sides.
    readonly property int tileWidth: photoActions.tileWidth
    readonly property int captionHeight: photoActions.localPhotoCaptions ? 24 : 0
    readonly property int tileGap: photoActions.localPhotoCaptions ? 7 : 14
    // Purpose: Keep the grid's leading and vertical edges clear of card chrome.
    // Type: int. Units: logical pixels. Scope: left, top and bottom grid edges.
    // Consumers: grid anchors. Rationale: 8 leaves the selection rim visible.
    readonly property int gridInset: photoActions.localPhotoCaptions ? 0 : 8
    // Purpose: Preserve the Tk column threshold at the linked grid's trailing edge.
    // Type: int. Units: logical pixels. Scope: right edge of linked photo grid.
    // Consumers: grid anchors. Rationale: Hosted wide geometry left Qt 2 px
    // below the 632 px needed for four 158 px cells; the tile keeps 20 px clear.
    readonly property int linkedGridRightInset: 0
    // Purpose: Match the inherited selected photo rim.
    // Type: int. Units: logical pixels. Scope: selected image tile.
    // Consumers: tile selectedRim. Rationale: Tk's shared tile border inset is 4.
    readonly property int selectedRimWidth: 4
    // Purpose: Keep keyboard focus visible without looking like selection.
    // Type: int. Units: logical pixels. Scope: current keyboard tile.
    // Consumers: tile focusRim. Rationale: A 2 px line is visible but subordinate.
    readonly property int focusRimWidth: 2
    // Purpose: Keep the grid scrollbar compact while leaving the viewport bounds fixed.
    // Type: int. Units: logical pixels. Scope: Photos grid overlay scrollbar.
    // Consumers: gridScrollBar. Rationale: A narrow overlay preserves cell and cache geometry.
    readonly property int scrollbarWidth: 12
    // Purpose: Keep the draggable thumb usable for short scroll ranges.
    // Type: int. Units: logical pixels. Scope: Photos grid overlay scrollbar.
    // Consumers: gridScrollBar. Rationale: 32 remains targetable without obscuring a tile row.
    readonly property int scrollbarMinimumThumbHeight: 32
    // Type: real. Units: ratio. Scope: one Lightbox retained frame.
    // Consumers: zoom commands. Rationale: match Tk's 1.25 step and 3x limit
    // while reusing exactly one source texture.
    readonly property real zoomStep: 1.25
    readonly property real maximumZoom: 3
    // Purpose: Match Tk's fine wheel zoom separately from toolbar zoom.
    // Type: real. Units: scale ratio. Scope: one complete wheel step.
    // Consumers: lightbox WheelHandler. Rationale: 1.1 retains fine adjustment.
    readonly property real wheelZoomStep: 1.1
    // Purpose: Normalize angle and precise touchpad input into whole steps.
    // Type: int. Units: eighths of a degree and logical pixels respectively.
    // Scope: Lightbox only. Consumers: lightbox WheelHandler. Rationale: 120
    // is a Qt wheel notch and 8 matches Tk's precise-scroll threshold.
    readonly property int wheelNotchUnits: 120
    readonly property int touchpadStepUnits: 8
    // Purpose: Preserve the regular Lightbox's reference canvas insets.
    // Type: size. Units: logical pixels. Scope: non-fullscreen viewport fit.
    // Consumers: lightbox.viewportScale. Rationale: Tk reserves 48×92 pixels
    // around its regular photo and uses the complete canvas in fullscreen.
    readonly property size regularLightboxInsets: Qt.size(48, 92)
    // Purpose: Keep the centered Lightbox status legible over a retained image.
    // Type: int. Units: logical pixels. Scope: the loading and error badge.
    // Consumers: lightboxStatus. Rationale: 8 matches compact Photos surfaces.
    readonly property int statusInset: 8
    // Purpose: Share one turn across only the thumbnail arrows currently shown.
    // Type: int. Units: milliseconds per revolution. Scope: active grid loads.
    // Consumers: loadingAngle animation. Rationale: 850 ms stays legible
    // during a delayed response without creating per-card animation clocks.
    readonly property int loadingTurnMilliseconds: 850
    property real loadingAngle: 0

    NumberAnimation on loadingAngle {
        from: 0
        to: 360 // One complete turn in Item.rotation degrees.
        duration: scene.loadingTurnMilliseconds
        loops: Animation.Infinite
        running: grid.visible && photoActions.photosActive && photoModel.thumbnailsLoading
    }

    HoverHandler {
        enabled: photoActions.detailOpen
        onPointChanged: photoActions.lightboxPointerMoved()
    }

    GridView {
        id: grid
        objectName: "photosGrid"
        anchors.fill: parent
        anchors.leftMargin: scene.gridInset
        anchors.topMargin: scene.gridInset
        anchors.bottomMargin: scene.gridInset
        anchors.rightMargin: photoActions.localPhotoCaptions
                                 ? scene.gridInset : scene.linkedGridRightInset
        visible: !photoActions.detailOpen
        focus: visible
        clip: true
        model: photoModel
        cellWidth: scene.tileWidth + scene.tileGap
        cellHeight: photoActions.tileHeight + scene.captionHeight + scene.tileGap
        reuseItems: true
        // The scene retains delegates only for its own visible region.
        cacheBuffer: 0
        keyNavigationEnabled: true
        boundsBehavior: Flickable.StopAtBounds

        // Request every intersecting row, including partial rows at both edges.
        // GridView can shift its origin when the model or viewport changes.
        function observeViewport() {
            if (!visible || !photoActions.photosActive)
                return
            const columns = Math.max(1, Math.floor(width / cellWidth))
            const offset = Math.max(0, contentY - originY)
            const firstRow = Math.floor(offset / cellHeight)
            const lastRow = Math.ceil((offset + height) / cellHeight)
            photoActions.viewport(Math.min(count, firstRow * columns),
                                  Math.min(count, lastRow * columns))
        }

        onContentYChanged: observeViewport()
        onOriginYChanged: observeViewport()
        onWidthChanged: observeViewport()
        onHeightChanged: observeViewport()
        onCellHeightChanged: observeViewport()
        onCountChanged: observeViewport()
        onCurrentIndexChanged: photoActions.focusedPhoto(currentIndex)
        onVisibleChanged: {
            if (visible) {
                forceActiveFocus()
                observeViewport()
            }
        }

        Keys.onReturnPressed: photoActions.openPhoto(currentIndex)
        Keys.onEnterPressed: photoActions.openPhoto(currentIndex)
        Keys.onSpacePressed: photoActions.choosePhoto(currentIndex, false, false)
        Keys.onPressed: function(event) {
            if (currentItem && (event.key === Qt.Key_Menu ||
                    (event.key === Qt.Key_F10 && (event.modifiers & Qt.ShiftModifier)))) {
                const anchor = currentItem.mapToItem(scene, 0, currentItem.height)
                photoActions.photoMenu(currentIndex, Math.round(anchor.x), Math.round(anchor.y))
                event.accepted = true
            }
        }

        TapHandler {
            acceptedButtons: Qt.RightButton | Qt.MiddleButton
            onTapped: function(point) {
                const pos = point.position
                if (grid.indexAt(grid.contentX + pos.x, grid.contentY + pos.y) < 0) {
                    const anchor = grid.mapToItem(scene, pos.x, pos.y)
                    photoActions.gallerySpaceMenu(Math.round(anchor.x), Math.round(anchor.y))
                }
            }
        }

        Connections {
            target: photoActions
            function onPhotosActiveChanged() {
                if (photoActions.photosActive)
                    grid.observeViewport()
            }
            function onScrollTo(row) {
                grid.positionViewAtIndex(row, GridView.Contain)
                grid.currentIndex = row
                grid.observeViewport()
            }
            function onRestoreScroll(first, focus) {
                grid.currentIndex = focus
                grid.positionViewAtIndex(first, GridView.Beginning)
                grid.observeViewport()
            }
        }

        delegate: Rectangle {
            id: tile
            objectName: "photoTile"
            required property int index
            required property string photoTitle
            required property string textureUrl
            required property bool photoSelected
            required property bool photoLoading
            property bool pooled: false
            width: grid.cellWidth - scene.tileGap
            height: grid.cellHeight - scene.tileGap
            color: desktopColors.card_surface
            Accessible.role: Accessible.Button
            Accessible.name: photoTitle
            Accessible.onPressAction: photoActions.openPhoto(index)
            GridView.onPooled: pooled = true
            GridView.onReused: pooled = false

            Image {
                id: thumbnail
                objectName: "photoThumbnail"
                anchors.top: parent.top
                anchors.horizontalCenter: parent.horizontalCenter
                width: parent.width
                height: photoActions.tileHeight
                fillMode: photoActions.cropTiles ? Image.PreserveAspectCrop : Image.PreserveAspectFit
                clip: true
                source: tile.pooled ? "" : tile.textureUrl
                cache: false
                smooth: true
            }

            Rectangle {
                anchors.centerIn: thumbnail
                readonly property real rasterWidth: thumbnail.status === Image.Ready
                    ? Math.min(thumbnail.width, thumbnail.paintedWidth) : thumbnail.width
                readonly property real rasterHeight: thumbnail.status === Image.Ready
                    ? Math.min(thumbnail.height, thumbnail.paintedHeight) : thumbnail.height
                width: rasterWidth
                height: rasterHeight
                color: "transparent"
                border.color: tile.photoSelected ? desktopColors.primary
                            : tile.GridView.isCurrentItem && grid.activeFocus ? desktopColors.focus
                            : "transparent"
                border.width: tile.photoSelected ? scene.selectedRimWidth
                             : tile.GridView.isCurrentItem && grid.activeFocus ? scene.focusRimWidth : 0
                radius: scene.focusRimWidth * 2
                z: 1
            }

            Text {
                anchors.centerIn: thumbnail
                visible: thumbnail.status !== Image.Ready && !loadingArrow.visible
                text: "▧"
                font.pixelSize: 30
                color: desktopColors.muted
                Accessible.ignored: true
            }

            Image {
                id: loadingArrow
                objectName: "photoThumbnailLoading"
                anchors.centerIn: thumbnail
                visible: !tile.pooled && tile.photoLoading && thumbnail.status !== Image.Ready
                source: visible ? "image://photos/ui/thumbnail-loading" : ""
                rotation: visible ? scene.loadingAngle : 0
                Accessible.ignored: true
            }

            Text {
                anchors.bottom: parent.bottom
                visible: photoActions.localPhotoCaptions
                anchors.horizontalCenter: parent.horizontalCenter
                width: parent.width - scene.tileGap
                height: scene.captionHeight
                text: tile.photoTitle
                textFormat: Text.PlainText
                elide: Text.ElideRight
                horizontalAlignment: Text.AlignHCenter
                verticalAlignment: Text.AlignVCenter
                color: desktopColors.ink
            }

            MouseArea {
                anchors.fill: parent
                acceptedButtons: Qt.LeftButton | Qt.RightButton | Qt.MiddleButton
                onClicked: function(mouse) {
                    grid.currentIndex = tile.index
                    const macControlContext = Qt.platform.os === "osx"
                            && mouse.button === Qt.LeftButton
                            && (mouse.modifiers & Qt.ControlModifier)
                    if (mouse.button === Qt.RightButton
                            || mouse.button === Qt.MiddleButton || macControlContext) {
                        const anchor = tile.mapToItem(scene, mouse.x, mouse.y)
                        photoActions.photoMenu(tile.index, Math.round(anchor.x), Math.round(anchor.y))
                        return
                    }
                    photoActions.choosePhoto(tile.index, !!(mouse.modifiers & Qt.ControlModifier),
                                             !!(mouse.modifiers & Qt.ShiftModifier))
                }
                onDoubleClicked: function(mouse) {
                    if (mouse.button === Qt.LeftButton)
                        photoActions.openPhoto(tile.index)
                }
            }
        }
    }

    FocusScope {
        id: gridScrollBar
        objectName: "photosGridScrollbar"
        z: 2
        anchors.top: grid.top
        anchors.right: grid.right
        anchors.bottom: grid.bottom
        width: scene.scrollbarWidth
        visible: grid.visible && grid.contentHeight > grid.height
        activeFocusOnTab: true
        Accessible.role: Accessible.ScrollBar
        Accessible.name: qsTr("Photos scrollbar")
        Accessible.description: qsTr("Drag or click to scroll the photo grid")
        property real maximumContentY: Math.max(0, grid.contentHeight - grid.height)
        property real thumbHeight: Math.min(
            height,
            Math.max(scene.scrollbarMinimumThumbHeight,
                     height * height / Math.max(1, grid.contentHeight)))
        property real maximumThumbY: Math.max(0, height - thumbHeight)

        // Convert the local pointer and grab offset to the view's scroll origin.
        function moveTo(y, offset) {
            const thumbY = Math.max(0, Math.min(maximumThumbY, y - offset))
            grid.contentY = grid.originY + (maximumThumbY > 0
                    ? thumbY / maximumThumbY * maximumContentY : 0)
        }

        Rectangle {
            anchors.fill: parent
            radius: scene.tileGap
            color: desktopColors.line
            opacity: 0.42

            Rectangle {
                id: scrollbarThumb
                objectName: "photosGridScrollbarThumb"
                x: 0
                y: gridScrollBar.maximumContentY > 0
                   ? (grid.contentY - grid.originY) / gridScrollBar.maximumContentY
                     * gridScrollBar.maximumThumbY : 0
                width: parent.width
                height: gridScrollBar.thumbHeight
                radius: scene.tileGap
                color: desktopColors.focus
            }
        }

        MouseArea {
            anchors.fill: parent
            preventStealing: true
            property real grabOffset: 0
            onPressed: function(mouse) {
                gridScrollBar.forceActiveFocus()
                const thumbTop = scrollbarThumb.y
                grabOffset = mouse.y >= thumbTop
                        && mouse.y <= thumbTop + scrollbarThumb.height
                        ? mouse.y - thumbTop : scrollbarThumb.height / 2
                gridScrollBar.moveTo(mouse.y, grabOffset)
            }
            onPositionChanged: function(mouse) {
                if (pressed)
                    gridScrollBar.moveTo(mouse.y, grabOffset)
            }
        }

        Keys.onPressed: function(event) {
            if (event.key === Qt.Key_Up) {
                grid.contentY = Math.max(grid.originY, grid.contentY - grid.cellHeight)
            } else if (event.key === Qt.Key_Down) {
                grid.contentY = Math.min(grid.originY + gridScrollBar.maximumContentY,
                                         grid.contentY + grid.cellHeight)
            } else if (event.key === Qt.Key_PageUp) {
                grid.contentY = Math.max(grid.originY, grid.contentY - grid.height)
            } else if (event.key === Qt.Key_PageDown) {
                grid.contentY = Math.min(grid.originY + gridScrollBar.maximumContentY,
                                         grid.contentY + grid.height)
            } else if (event.key === Qt.Key_Home) {
                grid.contentY = grid.originY
            } else if (event.key === Qt.Key_End) {
                grid.contentY = grid.originY + gridScrollBar.maximumContentY
            } else {
                return
            }
            event.accepted = true
        }
    }

    Rectangle {
        anchors.fill: parent
        visible: photoActions.detailOpen
        color: "#0B1018"
        z: 3
    }

    Flickable {
        id: lightbox
        objectName: "photosLightbox"
        anchors.fill: parent
        visible: photoActions.detailOpen
        z: 4
        focus: visible
        clip: true
        boundsBehavior: Flickable.StopAtBounds
        property real zoom: 1
        property int wheelRemainder: 0
        property int touchpadRemainder: 0
        readonly property rect crop: photoActions.contentCropEnabled
            ? photoActions.detailCropRect : Qt.rect(0, 0, 1, 1)
        readonly property bool fullscreen: photoActions.detailFullscreen
        readonly property real viewportScale: Math.min(
            Math.max(1, width - (fullscreen ? 0 : scene.regularLightboxInsets.width))
                / Math.max(1, image.implicitWidth * crop.width),
            Math.max(1, height - (fullscreen ? 0 : scene.regularLightboxInsets.height))
                / Math.max(1, image.implicitHeight * crop.height))
        readonly property real fit: fullscreen ? viewportScale : Math.min(1, viewportScale)
        contentWidth: Math.max(width, detailFrame.width)
        contentHeight: Math.max(height, detailFrame.height)
        onCropChanged: {
            if (image.frameReady && !photoActions.contentCropEnabled)
                Qt.callLater(photoActions.detailImageReady)
        }
        onVisibleChanged: {
            if (visible) {
                fitPhoto()
                forceActiveFocus()
            }
        }

        // Apply the shared zoom increment to the retained detail texture.
        function zoomIn() {
            zoom = Math.min(scene.maximumZoom, zoom * scene.zoomStep)
        }
        // Reduce detail scale without crossing the fitted frame size.
        function zoomOut() {
            zoom = Math.max(1, zoom / scene.zoomStep)
        }
        // Restore the viewport-fit scale for the current detail image.
        function fitPhoto() {
            zoom = 1
            wheelRemainder = 0
            touchpadRemainder = 0
            contentX = 0
            contentY = 0
        }

        Keys.onEscapePressed: photoActions.escapeLightbox()
        Keys.onLeftPressed: photoActions.stepPhoto(-1)
        Keys.onRightPressed: photoActions.stepPhoto(1)
        Keys.onPressed: function(event) {
            if (event.key === Qt.Key_Plus || event.key === Qt.Key_Equal) {
                zoomIn()
                event.accepted = true
            } else if (event.key === Qt.Key_Minus) {
                zoomOut()
                event.accepted = true
            } else if (event.key === Qt.Key_0) {
                fitPhoto()
                event.accepted = true
            } else if (event.key === Qt.Key_F11) {
                photoActions.toggleFullscreen()
                event.accepted = true
            }
        }

        Connections {
            target: photoActions
            function onZoomInRequested() { lightbox.zoomIn() }
            function onZoomOutRequested() { lightbox.zoomOut() }
            function onZoomFitRequested() { lightbox.fitPhoto() }
        }

        // The retained image provider supplies one full texture. Position that
        // texture behind a scene clip so toggling the matte needs no new read.
        Item {
            id: detailFrame
            objectName: "photosLightboxFrame"
            x: (lightbox.contentWidth - width) / 2
            y: (lightbox.contentHeight - height) / 2
            width: image.width * lightbox.crop.width
            height: image.height * lightbox.crop.height
            clip: photoActions.contentCropEnabled

            Image {
                id: image
                objectName: "photosLightboxImage"
                readonly property bool frameReady: status === Image.Ready
                x: -width * lightbox.crop.x
                y: -height * lightbox.crop.y
                width: implicitWidth * lightbox.fit * lightbox.zoom
                height: implicitHeight * lightbox.fit * lightbox.zoom
                source: photoActions.detailUrl
                fillMode: Image.PreserveAspectFit
                retainWhileLoading: true
                cache: false
                smooth: true
                onStatusChanged: {
                    if (frameReady)
                        photoActions.detailImageReady()
                }
                Accessible.role: Accessible.Graphic
                Accessible.name: photoActions.detailTitle
            }
        }

        Binding {
            target: photoActions
            property: "hdrImageRect"
            value: Qt.rect(detailFrame.x - lightbox.contentX,
                           detailFrame.y - lightbox.contentY,
                           detailFrame.width, detailFrame.height)
            when: lightbox.visible
        }

        WheelHandler {
            target: null
            enabled: lightbox.visible && image.frameReady
            acceptedDevices: PointerDevice.Mouse | PointerDevice.TouchPad
            onWheel: function(event) {
                const pixels = event.pixelDelta
                const precise = pixels.x !== 0 || pixels.y !== 0
                const vertical = precise ? pixels.y : event.angleDelta.y
                if (vertical === 0)
                    return
                const threshold = precise ? scene.touchpadStepUnits : scene.wheelNotchUnits
                const accumulated = vertical + (precise ? lightbox.touchpadRemainder
                                                        : lightbox.wheelRemainder)
                const steps = Math.trunc(accumulated / threshold)
                const remainder = accumulated - steps * threshold
                if (precise)
                    lightbox.touchpadRemainder = remainder
                else
                    lightbox.wheelRemainder = remainder
                if (steps !== 0) {
                    const limit = Math.ceil(Math.log(scene.maximumZoom)
                                            / Math.log(scene.wheelZoomStep))
                    const bounded = Math.max(-limit, Math.min(limit, steps))
                    const zoom = lightbox.zoom * Math.pow(scene.wheelZoomStep, bounded)
                    lightbox.zoom = Math.max(1, Math.min(scene.maximumZoom, zoom))
                }
                event.accepted = true
            }
        }
    }

    Rectangle {
        id: lightboxStatus
        objectName: "photosLightboxStatus"
        anchors.centerIn: parent
        z: lightbox.z + 1
        visible: photoActions.detailOpen && statusCaption.text !== ""
        width: Math.min(scene.width, statusCaption.implicitWidth + 2 * scene.statusInset)
        height: statusCaption.implicitHeight + 2 * scene.statusInset
        color: desktopColors.card_surface
        radius: scene.statusInset

        Text {
            id: statusCaption
            anchors.fill: parent
            anchors.margins: scene.statusInset
            text: photoActions.detailFeedback
            textFormat: Text.PlainText
            wrapMode: Text.WordWrap
            horizontalAlignment: Text.AlignHCenter
            color: photoActions.detailFailed ? desktopColors.danger : desktopColors.ink
            Accessible.role: Accessible.StaticText
        }
    }
}
